<?php

namespace Tests\Feature;

use App\Services\AuditLogger;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

/**
 * An audit line that is never written is worse than one that was never asked for.
 *
 * The marketplace services take their logger as `?AuditLogger $audit = null`, which reads as
 * "injected in production, overridable in a test". It was not: Laravel's container returns a
 * parameter's default without attempting resolution unless the class is explicitly bound, so every
 * one of those constructors received null and all seventeen `$this->audit?->record()` calls across
 * eight services were silent no-ops. Stock adjustments, purchase orders, returns, warehouse
 * transfers, rate changes, SLA breaches and KYC decisions all looked audited and were not — and
 * nothing failed, because `?->` on null is a no-op and the record itself swallows its own errors.
 *
 * So the wiring is checked mechanically rather than trusted: whichever services carry an audit
 * property must actually receive a logger when the container builds them.
 */
class AuditTrailIsWiredTest extends TestCase
{
    /**
     * Every service that records an audit line, discovered from the source rather than listed here,
     * so a service added later is covered without anyone remembering to add it.
     *
     * @return array<int, class-string>
     */
    private function servicesThatRecord(): array
    {
        $services = [];

        foreach (File::allFiles(app_path('Services')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (!str_contains($source, 'audit?->record(') && !str_contains($source, 'audit->record(')) {
                continue;
            }

            $relative = str_replace([app_path(), '/', '.php'], ['App', '\\', ''], $file->getPathname());
            if (class_exists($relative)) {
                $services[] = $relative;
            }
        }

        return $services;
    }

    public function test_the_container_hands_a_logger_to_every_service_that_records(): void
    {
        $services = $this->servicesThatRecord();

        // A pass because nothing was found would be the worst outcome: it would keep passing after
        // the discovery above quietly stopped matching.
        $this->assertGreaterThanOrEqual(8, count($services),
            'Expected to discover the marketplace services that record audit lines.');

        foreach ($services as $service) {
            $instance = app($service);
            $reflection = new ReflectionClass($instance);

            if (!$reflection->hasProperty('audit')) {
                continue;
            }

            $property = $reflection->getProperty('audit');
            $property->setAccessible(true);

            $this->assertInstanceOf(AuditLogger::class, $property->getValue($instance),
                "{$service} records audit lines but the container handed it no logger, so every one "
                . 'of those calls is a silent no-op.');
        }
    }

    public function test_the_logger_is_bound_so_a_nullable_default_does_not_win(): void
    {
        // This binding is the whole mechanism. Without it the container short-circuits to the
        // `= null` default for every one of those constructors.
        $this->assertTrue(app()->bound(AuditLogger::class),
            'AuditLogger must be bound in the container, or nullable constructor defaults win and '
            . 'the marketplace audit trail goes silent again.');

        $this->assertSame(app(AuditLogger::class), app(AuditLogger::class),
            'The logger is stateless and should be shared rather than rebuilt per injection.');
    }

    public function test_a_caller_can_still_supply_its_own_logger(): void
    {
        // The nullable signature has to keep meaning what it says, or tests lose the seam.
        $own = new AuditLogger();
        $service = new \App\Services\Marketplace\InventoryService($own);

        $property = (new ReflectionClass($service))->getProperty('audit');
        $property->setAccessible(true);

        $this->assertSame($own, $property->getValue($service));
    }
}
