<?php

namespace App\Console\Commands;

use App\Models\ThemeVersion;
use App\Services\AuditLogger;
use App\Services\Theme\PublishValidator;
use App\Services\Theme\ThemeManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Publishes the drafts whose moment has come.
 *
 * A seasonal home page is composed days ahead and has to go live at a particular hour. Doing that
 * by hand means somebody at a keyboard at midnight, and the cost of missing it is a sale running
 * against last week's page.
 *
 * The check that guards a manual publish guards this one too, and for a better reason: hours pass
 * between scheduling and firing, and a section can be fine when the merchant sets the time and
 * broken by the time it arrives — a deleted category, a section edited and left half-finished. A
 * scheduled publish that shipped that anyway would be the worst version of this feature, because
 * nobody is watching when it runs.
 *
 * A blocked version does not retry. Its schedule is cleared and the reason recorded, so the merchant
 * finds a draft that did not go live and a list of what stopped it, instead of a failure that
 * repeats silently every five minutes.
 */
class ThemePublishDue extends Command
{
    protected $signature = 'theme:publish-due {--dry-run : Report what would publish, change nothing}';

    protected $description = 'Publish theme versions whose scheduled time has arrived';

    public function handle(ThemeManager $manager, PublishValidator $validator, AuditLogger $audit): int
    {
        if (!Schema::hasTable('theme_versions') || !Schema::hasColumn('theme_versions', 'publish_at')) {
            $this->info('Scheduled publishing is not installed on this database yet.');

            return self::SUCCESS;
        }

        $due = ThemeVersion::dueToPublish()->orderBy('publish_at')->get();

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($due as $version) {
            $findings = $validator->inspect($version);

            if ($findings['blocking'] !== []) {
                $this->cancel($version, $findings['blocking'], $audit);

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("Would publish version #{$version->id} of theme #{$version->theme_id}.");

                continue;
            }

            // Clearing the schedule BEFORE publishing: publish() is the expensive, cache-flushing,
            // beacon-announcing half, and a crash inside it must not leave a version that publishes
            // again on the next run.
            $version->forceFill(['publish_at' => null])->save();
            $manager->publish($version, $version->change_note);

            $this->info("Published version #{$version->id} of theme #{$version->theme_id}.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocking
     */
    private function cancel(ThemeVersion $version, array $blocking, AuditLogger $audit): void
    {
        $reasons = array_values(array_unique(array_map(
            static fn (array $finding) => $finding['label'] . ': ' . $finding['reason_key'],
            $blocking,
        )));

        $this->warn("Version #{$version->id} was due but is not publishable: " . implode('; ', $reasons));

        if ($this->option('dry-run')) {
            return;
        }

        $version->forceFill(['publish_at' => null])->save();

        $audit->record(
            action: 'theme.scheduled_publish_cancelled',
            subject: $version,
            after: ['reasons' => $reasons],
            context: ['theme_id' => $version->theme_id],
        );
    }
}
