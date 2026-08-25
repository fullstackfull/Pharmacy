<?php

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\Ingest\ExceptionRecorder;
use App\Services\Monitoring\Support\MonitoringSettings;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The error console had readers and no writer.
 *
 * Two tables shipped in the migration, eight panels queried them, the rollup pruned them, and no
 * code path in the application ever inserted a row — so every error screen was empty on every
 * installation and the health score counted zero new error groups forever. These tests hold the
 * seam that closes it, and the judgement calls that make the result readable rather than a firehose:
 * one bug is one group however many customers hit it, a resolved group that fires again is open
 * again, and ordinary traffic — a login prompt, a failed validation, a 404 — is not a fault.
 */
class ExceptionCaptureTest extends TestCase
{
    private const CONNECTION = 'monitoring_test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.' . self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        config()->set('monitoring.connection', self::CONNECTION);
        config()->set('monitoring.enabled', true);

        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->getPdo();

        foreach (glob(database_path('migrations/*_create_monitoring_*_tables.php')) as $migration) {
            (require $migration)->up();
        }

        app(MonitoringSettings::class)->forget();
    }

    private function recorder(): ExceptionRecorder
    {
        return app(ExceptionRecorder::class);
    }

    private function errorGroups()
    {
        return DB::connection(self::CONNECTION)->table('monitoring_error_groups');
    }

    private function errors()
    {
        return DB::connection(self::CONNECTION)->table('monitoring_errors');
    }

    /**
     * Every failure in these tests is raised from this one line on purpose.
     *
     * The fingerprint includes the topmost application frame, so two `new RuntimeException` calls
     * written on two different lines of a test file are — correctly — two different bugs. Raising
     * them from a single throw site is what makes "the same failure twice" actually the same
     * failure, the way a repeated fault in a controller is.
     */
    private function record(string $message): void
    {
        $this->recorder()->record(new RuntimeException($message));
    }

    public function test_a_reported_exception_opens_a_group_and_records_an_occurrence(): void
    {
        $this->record('Payment gateway refused the charge');

        $group = $this->errorGroups()->first();

        $this->assertNotNull($group);
        $this->assertSame(RuntimeException::class, $group->exception_class);
        $this->assertSame('open', $group->status);
        $this->assertSame(1, (int) $group->occurrences);
        $this->assertSame(1, $this->errors()->where('group_id', $group->id)->count());
    }

    /**
     * "14 errors in the last hour" tells nobody anything. The same failure hitting fourteen
     * customers has to arrive as one thing with a count on it.
     */
    public function test_the_same_failure_twice_is_one_group_with_two_occurrences(): void
    {
        $this->record('Payment gateway refused the charge');
        $this->record('Payment gateway refused the charge');

        $this->assertSame(1, $this->errorGroups()->count());
        $this->assertSame(2, (int) $this->errorGroups()->value('occurrences'));
        $this->assertSame(2, $this->errors()->count());
    }

    /** The variable half of a message is not part of the bug's identity. */
    public function test_the_same_bug_with_different_ids_in_its_message_is_one_group(): void
    {
        $this->record('No query results for model [Product] 41');
        $this->record('No query results for model [Product] 9182');

        $this->assertSame(1, $this->errorGroups()->count());
        $this->assertSame(2, (int) $this->errorGroups()->value('occurrences'));
    }

    public function test_two_different_failures_never_merge(): void
    {
        $this->record('Payment gateway refused the charge');
        $this->record('Warehouse stock could not be reserved');

        $this->assertSame(2, $this->errorGroups()->count());
    }

    /**
     * A regression that stays silent because somebody once ticked "resolved" is the failure mode
     * this whole table exists to prevent.
     */
    public function test_a_resolved_group_reopens_when_it_happens_again(): void
    {
        $this->record('Payment gateway refused the charge');

        $this->errorGroups()->update([
            'status' => 'resolved',
            'resolved_at' => '2026-08-20 10:00:00',
            'resolved_by' => 7,
        ]);

        $this->record('Payment gateway refused the charge');

        $group = $this->errorGroups()->first();

        $this->assertSame('open', $group->status);
        $this->assertNull($group->resolved_at);
        $this->assertNull($group->resolved_by);
    }

    /** A group deliberately silenced stays silenced; only "resolved" means "we believe it is gone". */
    public function test_an_ignored_group_stays_ignored(): void
    {
        $this->record('Broken pipe');
        $this->errorGroups()->update(['status' => 'ignored']);
        $this->record('Broken pipe');

        $this->assertSame('ignored', $this->errorGroups()->value('status'));
        $this->assertSame(2, (int) $this->errorGroups()->value('occurrences'));
    }

    /**
     * A login prompt, a rejected form and a mistyped URL are the application working. Recording
     * them here would bury the one exception that matters under the noise of a normal Tuesday.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ordinaryTraffic')]
    public function test_ordinary_traffic_is_not_recorded_as_a_fault(callable $make): void
    {
        $this->recorder()->record($make());

        $this->assertSame(0, $this->errorGroups()->count());
        $this->assertSame(0, $this->errors()->count());
    }

    /** Factories, not instances: a provider runs before the application is booted. */
    public static function ordinaryTraffic(): array
    {
        return [
            'unauthenticated' => [fn () => new AuthenticationException()],
            'not found' => [fn () => new NotFoundHttpException('No route')],
            'forbidden' => [fn () => new AccessDeniedHttpException('Nope')],
            'validation' => [fn () => ValidationException::withMessages(['email' => 'required'])],
        ];
    }

    /** A password in a stack trace is a breach that cannot be undone by fixing a view later. */
    public function test_secrets_in_the_message_are_masked_before_they_are_stored(): void
    {
        $this->record('Refused with api_key=sk_live_9182 supplied');

        $this->assertStringNotContainsString('sk_live_9182', (string) $this->errorGroups()->value('message'));
    }

    /** Recording an error must never be the reason a request fails. */
    public function test_a_missing_monitoring_table_is_survived_silently(): void
    {
        DB::connection(self::CONNECTION)->statement('DROP TABLE monitoring_error_groups');

        $this->record('Payment gateway refused the charge');

        $this->assertSame(0, $this->errors()->count());
    }

    /**
     * The recorder is only worth having if the framework actually calls it.
     *
     * This goes through the application's own exception handler rather than the recorder, so the
     * `withExceptions` registration in bootstrap/app.php is what is under test — deleting that one
     * line puts the console back to permanently empty and fails here.
     */
    public function test_the_application_exception_handler_feeds_the_console(): void
    {
        Log::spy();

        app(ExceptionHandler::class)->report(new RuntimeException('Reported through the handler'));

        $this->assertSame(1, $this->errorGroups()->count());
        $this->assertSame('Reported through the handler', $this->errorGroups()->value('message'));
    }

    public function test_capture_can_be_switched_off_entirely(): void
    {
        config()->set('monitoring.enabled', false);

        $this->record('Payment gateway refused the charge');

        $this->assertSame(0, $this->errorGroups()->count());
    }
}
