<?php

namespace Tests\Feature;

use App\Services\Monitoring\FailedJobs;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A failed job must be repairable from the page that shows it failed.
 *
 * Monitoring is read-only by design, and this is the one exception the product asks for. The reason
 * is that the alternative leaves the person who can see the problem unable to fix it: an order
 * confirmation that failed at two in the morning appears on the Queues page, and putting it back on
 * the queue was a shell command.
 */
class FailedJobRetryTest extends TestCase
{
    public function test_retrying_puts_the_job_back_on_the_queue_it_came_from(): void
    {
        $this->fakeFailedStore([
            'uuid' => 'job-1',
            'connection' => 'redis',
            'queue' => 'emails',
            'payload' => '{"job":"SendOrderConfirmation"}',
        ]);

        Queue::fake();

        $result = app(FailedJobs::class)->retry('job-1');

        $this->assertTrue($result['ok']);
        // Back onto the connection and queue it came from: a retry that lands somewhere else is a
        // different job.
        $this->assertSame([['redis', '{"job":"SendOrderConfirmation"}', 'emails']], $this->pushed);
        $this->assertSame(['job-1'], $this->forgotten, 'a retried job leaves the failed list');
    }

    public function test_discarding_removes_it_without_running_it_again(): void
    {
        $this->fakeFailedStore(['uuid' => 'job-2', 'connection' => 'redis', 'queue' => 'emails', 'payload' => '{}']);

        $result = app(FailedJobs::class)->forget('job-2');

        $this->assertTrue($result['ok']);
        $this->assertSame(['job-2'], $this->forgotten);
        $this->assertSame([], $this->pushed, 'discarding must not run the job');
    }

    public function test_a_job_somebody_else_already_retried_is_a_message_not_an_error(): void
    {
        $this->fakeFailedStore(null);

        // Two operators looking at the same page is the ordinary case.
        foreach (['retry', 'forget'] as $action) {
            $result = app(FailedJobs::class)->{$action}('job-gone');

            $this->assertFalse($result['ok']);
            $this->assertSame('that_job_is_no_longer_in_the_failed_list', $result['reason']);
        }

        $this->assertSame([], $this->pushed);
    }

    public function test_an_empty_identifier_is_refused_rather_than_searched_for(): void
    {
        $this->fakeFailedStore(['uuid' => '', 'connection' => 'redis', 'queue' => 'e', 'payload' => '{}']);

        $this->assertFalse(app(FailedJobs::class)->retry('')['ok']);
        $this->assertFalse(app(FailedJobs::class)->retry('   ')['ok']);
    }

    /** @var array<int, array{0: ?string, 1: string, 2: ?string}> */
    private array $pushed = [];

    /** @var array<int, string> */
    private array $forgotten = [];

    /** Stand in for whichever failed-job driver an install runs, holding one record or none. */
    private function fakeFailedStore(?array $job): void
    {
        $this->pushed = [];
        $this->forgotten = [];

        $found = $job === null ? null : (object) $job;
        $forgotten = &$this->forgotten;

        $this->app->instance(FailedJobProviderInterface::class, new class ($found, $forgotten) implements FailedJobProviderInterface {
            public function __construct(private readonly ?object $job, private array &$forgotten)
            {
            }

            public function log($connection, $queue, $payload, $exception)
            {
            }

            public function all()
            {
                return $this->job === null ? [] : [$this->job];
            }

            public function ids($queue = null)
            {
                return $this->job === null ? [] : [$this->job->uuid];
            }

            public function find($id)
            {
                return $this->job !== null && ($this->job->uuid ?? null) === $id ? $this->job : null;
            }

            public function forget($id)
            {
                $this->forgotten[] = $id;

                return true;
            }

            public function flush($hours = null)
            {
            }
        });

        $pushed = &$this->pushed;

        $this->app->instance(QueueFactory::class, new class ($pushed) implements QueueFactory {
            public function __construct(private array &$pushed)
            {
            }

            public function connection($name = null)
            {
                $pushed = &$this->pushed;

                return new class ($name, $pushed) {
                    public function __construct(private readonly ?string $name, private array &$pushed)
                    {
                    }

                    public function pushRaw($payload, $queue = null, array $options = [])
                    {
                        $this->pushed[] = [$this->name, $payload, $queue];

                        return 'pushed';
                    }
                };
            }
        });
    }
}
