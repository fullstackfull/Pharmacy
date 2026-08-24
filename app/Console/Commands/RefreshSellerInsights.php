<?php

namespace App\Console\Commands;

use App\Models\Seller;
use App\Services\SellerIntelligence\SellerInsightEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Recompute what every seller should be looking at.
 *
 * Runs for approved sellers only: a suspended or pending account has no Action Center to fill, and
 * computing for them would be work nobody reads.
 */
class RefreshSellerInsights extends Command
{
    protected $signature = 'seller:refresh-insights
                            {--seller= : Only this seller, for a targeted refresh}
                            {--type=* : Only these insight types}';

    protected $description = 'Recompute seller insights (Action Center, home alerts)';

    public function handle(SellerInsightEngine $engine): int
    {
        if (!Schema::hasTable('seller_insights')) {
            $this->warn('seller_insights table is not present; nothing to do.');

            return self::SUCCESS;
        }

        $types = $this->option('type') ?: null;
        $sellerIds = $this->option('seller')
            ? [(int) $this->option('seller')]
            : Seller::approved()->pluck('id')->all();

        $written = 0;
        $resolved = 0;

        foreach ($sellerIds as $sellerId) {
            $result = $engine->refresh($sellerId, $types);
            $written += $result['written'];
            $resolved += $result['resolved'];
        }

        $this->info(sprintf(
            '%d seller(s): %d insight(s) written, %d resolved.',
            count($sellerIds), $written, $resolved,
        ));

        return self::SUCCESS;
    }
}
