<?php

namespace App\Console\Commands;

use App\Services\Monitoring\Alerting\AlertEvaluator;
use Illuminate\Console\Command;

/**
 * Compare every enabled alert rule against what was actually measured this minute.
 *
 * Collection without evaluation is a wall of charts nobody is watching at three in the morning.
 * This is the part that decides something is wrong and says so — and, just as importantly, the
 * part that stays quiet: a rule only fires once its condition has held for its whole window, and a
 * metric that stopped arriving fires nothing at all, because absent is not zero.
 */
class MonitoringEvaluate extends Command
{
    protected $signature = 'monitoring:evaluate
                            {--seed : Install the shipped alert rules on a system that has none}
                            {--force-seed : Re-install any shipped rule that has been deleted}
                            {--quiet-ok : Only print rules that are not ok}';

    protected $description = 'Evaluate the monitoring alert rules against the latest measurements';

    public function handle(AlertEvaluator $evaluator): int
    {
        if (!config('monitoring.enabled', true)) {
            $this->warn('Monitoring is disabled (MONITORING_ENABLED=false); no rule was evaluated.');

            return self::SUCCESS;
        }

        if ($this->option('seed') || $this->option('force-seed')) {
            $created = $evaluator->seedDefaults(force: (bool) $this->option('force-seed'));
            $this->info($created === 0 ? 'No rule needed to be created.' : "Created {$created} alert rule(s).");
        }

        $outcomes = $evaluator->evaluate();

        if ($outcomes === []) {
            $this->warn('No alert rule is enabled. Run php artisan monitoring:evaluate --seed to install the shipped set, or add rules in Monitoring → Settings.');

            return self::SUCCESS;
        }

        $shown = $this->option('quiet-ok')
            ? array_filter($outcomes, static fn (array $outcome) => $outcome['state'] !== 'ok')
            : $outcomes;

        if ($shown !== []) {
            $this->table(
                ['Rule', 'Metric', 'State', 'Value', 'Note'],
                array_map(static fn (array $outcome) => [
                    $outcome['rule'],
                    $outcome['metric'],
                    $outcome['state'],
                    $outcome['value'] === null ? '—' : rtrim(rtrim(number_format((float) $outcome['value'], 2, '.', ''), '0'), '.'),
                    $outcome['note'],
                ], $shown),
            );
        }

        $firing = array_filter($outcomes, static fn (array $outcome) => in_array($outcome['state'], ['warning', 'critical'], true));

        $this->line(sprintf(
            '%d rule(s) evaluated, %d firing.',
            count($outcomes),
            count($firing),
        ));

        return self::SUCCESS;
    }
}
