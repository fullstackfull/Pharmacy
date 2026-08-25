<?php

namespace Tests\Feature;

use App\Console\ScheduleDefinition;
use App\Services\Monitoring\Collectors\SchedulerCollector;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * An operator must be able to see what is supposed to run, not only what happened to run.
 *
 * Laravel registers the schedule from `Artisan::starting`, so the container's Schedule is empty in
 * any process that is not a console command — which is every process that renders the admin
 * Scheduler page. The page could therefore list the runs it had recorded and nothing else: a task
 * that had never run once looked exactly like a task that does not exist, and a task that failed
 * last night could not say when it would try again.
 *
 * These tests run outside the console for the same reason a web request does, so an empty
 * definition here is the same failure an operator would see.
 */
class ScheduleVisibilityTest extends TestCase
{
    public function test_the_defined_schedule_is_readable_without_starting_a_console_process(): void
    {
        $readings = app(SchedulerCollector::class)->collect();

        $this->assertGreaterThan(
            0,
            $readings['defined_tasks']->value,
            'the schedule is invisible outside the console, which is where this page is rendered',
        );
    }

    public function test_every_defined_task_says_when_it_next_runs(): void
    {
        $tasks = app(SchedulerCollector::class)->collect()['tasks']->value;

        $this->assertNotEmpty($tasks);

        foreach ($tasks as $task) {
            $this->assertArrayHasKey('next_due_at', $task);
            $this->assertNotNull(
                $task['next_due_at'],
                "the task '{$task['task']}' does not say when it is next due",
            );
        }
    }

    public function test_a_history_that_cannot_be_read_does_not_hide_the_schedule(): void
    {
        // The recorded runs live on the monitoring connection. When that is unreachable — which is
        // exactly the moment an operator most wants this page — the table used to come back empty,
        // turning "I cannot reach the monitoring database" into "nothing is scheduled".
        config()->set('monitoring.connection', 'a-connection-that-is-not-configured');

        $tasks = app(SchedulerCollector::class)->collect()['tasks']->value;

        $this->assertNotEmpty($tasks, 'the defined schedule does not depend on the history');
        $this->assertNull($tasks[0]['last_run_at'], 'with no history every task reads as never run');
        $this->assertNotNull($tasks[0]['next_due_at'], 'and still says when it is next due');
    }

    public function test_the_definition_is_the_one_the_console_registers(): void
    {
        // Read from the same class the console reads, so a task added to the schedule is monitored
        // the moment it exists rather than when somebody remembers a second list.
        $schedule = new Schedule();
        ScheduleDefinition::define($schedule);

        $defined = collect($schedule->events())->count();
        $reported = app(SchedulerCollector::class)->collect()['defined_tasks']->value;

        $this->assertSame($defined, $reported);
        $this->assertGreaterThan(15, $defined, 'this platform runs more than a handful of scheduled tasks');
    }

    public function test_every_scheduled_task_can_be_named(): void
    {
        // A closure with no name is recorded as an anonymous task, and an operator cannot act on a
        // row that does not say what it is.
        $tasks = app(SchedulerCollector::class)->collect()['tasks']->value;

        foreach ($tasks as $task) {
            $this->assertNotSame('', trim((string) $task['task']));
            $this->assertStringNotContainsString('Closure', (string) $task['task']);
        }
    }
}
