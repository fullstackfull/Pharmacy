<?php

namespace Tests\Feature\Monitoring;

use App\Enums\GlobalConstant;
use App\Services\Monitoring\MonitoringNavigation;
use App\Services\Monitoring\MonitoringPermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Monitoring shows more about a business than almost any other screen, so who sees what is not an
 * afterthought — and adding those permissions must not break the ones that already exist.
 */
class MonitoringPermissionTest extends TestCase
{
    public function test_a_full_permission_set_fits_in_the_column_that_stores_it(): void
    {
        // This is the bug this test exists for: module_access was a varchar(250), the twelve
        // shipped module keys encode to 159 characters, and adding the six monitoring capabilities
        // takes a fully-privileged role to 287. With strict mode off (config/database.php sets
        // 'strict' => false) MariaDB truncates rather than rejecting — the stored JSON would be cut
        // mid-key, json_decode would return null, and an administrator who had just been granted
        // MORE permissions would silently lose ALL of them.
        $everything = array_merge(
            array_keys(GlobalConstant::EMPLOYEE_ROLE_MODULE_PERMISSION),
            array_keys(MonitoringPermissionService::all()),
        );
        $encoded = json_encode($everything);

        $this->assertGreaterThan(250, strlen($encoded), 'the overflow this guards against no longer reproduces — check the key list');

        if (!Schema::hasTable('admin_roles')) {
            $this->markTestSkipped('admin_roles is not present in this test schema.');
        }

        $type = collect(DB::select('SHOW COLUMNS FROM admin_roles LIKE ?', ['module_access']))->first()?->Type ?? '';
        $this->assertMatchesRegularExpression(
            '/^(text|mediumtext|longtext|varchar\((?:[3-9]\d{2}|\d{4,})\))$/i',
            strtolower($type),
            "admin_roles.module_access is {$type}, which cannot hold a full permission set ("
                . strlen($encoded) . ' characters)',
        );
    }

    public function test_every_capability_is_offered_in_the_role_editor(): void
    {
        // A capability the controller checks but the editor never offers can only ever be granted
        // by editing the database by hand.
        $editors = file_get_contents(resource_path('views/admin-views/custom-role/create.blade.php'))
            . file_get_contents(resource_path('views/admin-views/custom-role/edit.blade.php'));

        $this->assertStringContainsString('MonitoringPermissionService::all()', $editors);
        $this->assertSame(2, substr_count($editors, 'MonitoringPermissionService::all()'));
    }

    public function test_every_section_declares_a_capability_that_exists(): void
    {
        $permissions = new MonitoringPermissionService();
        $known = array_keys(MonitoringPermissionService::all());

        foreach (array_keys(MonitoringNavigation::sections()) as $section) {
            $this->assertContains(
                $permissions->capabilityForTab($section),
                $known,
                "Section {$section} requires a capability that is not on the list the editor offers.",
            );
        }
    }

    public function test_the_sensitive_capabilities_are_not_implied_by_the_coarse_module(): void
    {
        // Holding system_settings keeps the read-only view working for existing roles, but logs,
        // security, infrastructure and settings each expose data or change behaviour, so they have
        // to be granted deliberately rather than inherited.
        $implied = (new \ReflectionClass(MonitoringPermissionService::class))
            ->getConstant('IMPLIED_BY_MODULE');

        $this->assertContains(MonitoringPermissionService::VIEW, $implied);
        $this->assertNotContains(MonitoringPermissionService::LOGS, $implied);
        $this->assertNotContains(MonitoringPermissionService::SECURITY, $implied);
        $this->assertNotContains(MonitoringPermissionService::INFRASTRUCTURE, $implied);
        $this->assertNotContains(MonitoringPermissionService::SETTINGS, $implied);
    }

    public function test_dotted_capability_names_are_accepted_as_aliases(): void
    {
        // The specification asked for monitoring.view; the project's own convention is
        // theme_view. Both spellings resolve, so a role stored either way works.
        $aliases = (new \ReflectionClass(MonitoringPermissionService::class))->getConstant('ALIASES');

        foreach (array_keys(MonitoringPermissionService::all()) as $canonical) {
            $dotted = str_replace('monitoring_', 'monitoring.', $canonical);
            $this->assertArrayHasKey($dotted, $aliases, "The dotted spelling {$dotted} is not accepted.");
            $this->assertSame($canonical, $aliases[$dotted]);
        }
    }

    public function test_an_unauthenticated_visitor_holds_no_capability(): void
    {
        $permissions = new MonitoringPermissionService();

        foreach (array_keys(MonitoringPermissionService::all()) as $capability) {
            $this->assertFalse($permissions->can($capability));
        }
    }
}
