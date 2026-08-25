<?php

namespace App\Console\Commands;

use App\Services\Notifications\DeliveryLog;
use App\Services\Platform\Policy;
use Illuminate\Console\Command;

class NotificationDeliverySweep extends Command
{
    protected $signature = 'notifications:sweep {--prune : also delete records past their retention}';

    protected $description = 'Close out messages the transport never confirmed, and prune old delivery records';

    public function handle(DeliveryLog $log, Policy $policy): int
    {
        // A message whose transport threw, or whose worker died mid-job, leaves a row saying
        // "pending" for ever. Left alone it reads as "still going", which is the one thing it is
        // not, and it hides a failure inside a status that looks harmless.
        $closed = $log->closeStalled($policy->int('notification_unconfirmed_minutes'));
        $this->info($closed . ' unconfirmed message(s) closed as failed');

        if ($this->option('prune')) {
            $removed = $log->prune($policy->int('notification_log_retention_days'));
            $this->info($removed . ' delivery record(s) pruned');
        }

        return self::SUCCESS;
    }
}
