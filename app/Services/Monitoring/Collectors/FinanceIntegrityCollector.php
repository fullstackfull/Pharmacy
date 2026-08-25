<?php

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Metric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The money-losing conditions, as numbers a rule can be written against.
 *
 * The payments page really does detect these — a settlement written twice, a paid order with no
 * settlement row at all, commission that does not reconcile — but it computes them live on page load
 * and publishes nothing. So the alert engine, which can only read stored series, could not see them,
 * no rule could be written, and a seller who is silently never paid was found only if an admin
 * happened to open that section. The detection existed; the ability to be told did not.
 *
 * Its own bounded queries rather than a call into the panel, deliberately. The panel returns rows,
 * explanations, remedies and a cost guard for a human reading one screen; this needs three integers
 * once a minute, and running the whole page's query plan on the flush would make the monitoring
 * system the heaviest thing on the server. The panel stays the place you go to see WHICH orders;
 * this is only the place that says HOW MANY, which is all a threshold needs.
 *
 * The window is fixed at 24 hours rather than taken from the caller. A gauge is a reading of now,
 * and "duplicate settlements in the last day" is a number that can be compared against itself an
 * hour later; one whose window moved with whatever the page happened to be showing could not.
 */
class FinanceIntegrityCollector implements Collector
{
    /** How far back each count looks. Long enough to catch a nightly settlement run, short enough to stay indexed. */
    private const WINDOW_HOURS = 24;

    /** Beyond this the answer is "a lot", and counting further costs more than it says. */
    private const CEILING = 5000;

    /** Rounding slack on the commission identity, in the shop's currency. */
    private const COMMISSION_TOLERANCE = 0.01;

    public function key(): string
    {
        return 'finance';
    }

    /** @return array<string, Metric> */
    public function collect(): array
    {
        return [
            'duplicate_settlements' => $this->duplicateSettlements(),
            'paid_without_settlement' => $this->paidWithoutSettlement(),
            'commission_mismatch' => $this->commissionMismatch(),
        ];
    }

    /** @return array<string, Metric> */
    public function gauges(): array
    {
        $collected = $this->collect();

        // All three, because all three are exactly the shape a threshold wants: a count that should
        // be zero, and is an incident the moment it is not. Names are fully qualified here — the
        // flush writes the key it is given straight into monitoring_series, and that name is what an
        // operator types into a rule.
        return array_filter([
            'finance.duplicate_settlements' => $collected['duplicate_settlements'],
            'finance.paid_without_settlement' => $collected['paid_without_settlement'],
            'finance.commission_mismatch' => $collected['commission_mismatch'],
        ], static fn (Metric $metric) => $metric->isOk());
    }

    /**
     * More than one settlement row for one order.
     *
     * order_transactions has no unique key on order_id — no index on it at all — so a retried
     * settlement writes a second row and the vendor is credited twice for one sale.
     */
    private function duplicateSettlements(): Metric
    {
        return $this->count(
            source: 'MySQL order_transactions',
            note: 'Orders with more than one settlement row in the last ' . self::WINDOW_HOURS . ' hours. Each one is a vendor credited twice for a single sale.',
            query: function (): int {
                if (!Schema::hasTable('order_transactions')) {
                    return -1;
                }

                return DB::table('order_transactions')
                    ->where('created_at', '>=', $this->since())
                    ->select('order_id')
                    ->groupBy('order_id')
                    ->havingRaw('COUNT(*) > 1')
                    ->limit(self::CEILING)
                    ->get()
                    ->count();
            },
        );
    }

    /**
     * A paid, non-offline order with no settlement row.
     *
     * OrderManager writes one at generation time for every such order, so its absence means nothing
     * downstream will ever find this sale to disburse — the vendor is simply never paid for it.
     */
    private function paidWithoutSettlement(): Metric
    {
        return $this->count(
            source: 'MySQL orders left join order_transactions',
            note: 'Paid, non-offline orders in the last ' . self::WINDOW_HOURS . ' hours with no settlement row. Each one is a sale the vendor will never be paid for.',
            query: function (): int {
                if (!Schema::hasTable('orders') || !Schema::hasTable('order_transactions')) {
                    return -1;
                }

                return DB::table('orders')
                    ->where('orders.created_at', '>=', $this->since())
                    ->where('orders.payment_status', 'paid')
                    ->whereNotIn('orders.payment_method', ['cash_on_delivery', 'offline_payment', 'wallet_payment', 'wallet'])
                    ->whereNotExists(fn ($query) => $query
                        ->select(DB::raw(1))
                        ->from('order_transactions')
                        ->whereColumn('order_transactions.order_id', 'orders.id'))
                    ->limit(self::CEILING)
                    ->count();
            },
        );
    }

    /**
     * Settlement rows whose parts do not add up to the whole.
     *
     * seller_amount plus admin_commission has to equal order_amount. Where it does not, one of the
     * two parties is being paid from a number nobody computed.
     */
    private function commissionMismatch(): Metric
    {
        return $this->count(
            source: 'MySQL order_transactions',
            note: 'Settlement rows in the last ' . self::WINDOW_HOURS . ' hours where seller amount plus commission does not equal the order amount. Somebody is being paid from a figure nothing computed.',
            query: function (): int {
                if (!Schema::hasTable('order_transactions')) {
                    return -1;
                }

                return DB::table('order_transactions')
                    ->where('created_at', '>=', $this->since())
                    // The tolerance is inlined rather than bound. PDO binds a PHP float as a
                    // string, and SQLite orders every INTEGER below every TEXT — so `6 > '0.01'`
                    // came back false and the check silently found nothing. It is a class constant,
                    // not input, so there is nothing here to inject.
                    ->whereRaw(
                        'ABS(COALESCE(order_amount, 0) - COALESCE(seller_amount, 0) - COALESCE(admin_commission, 0)) > '
                        . self::COMMISSION_TOLERANCE,
                    )
                    ->limit(self::CEILING)
                    ->count();
            },
        );
    }

    /**
     * A count, or the reason there isn't one.
     *
     * A missing table comes back as not_configured rather than zero. Zero here means "checked and
     * clean", and it is the whole point of the metric — reporting it for a table that does not
     * exist would tell an operator the books balance on a shop that has no books.
     */
    private function count(string $source, string $note, callable $query): Metric
    {
        try {
            $value = $query();

            return $value < 0
                ? Metric::notConfigured(
                    source: $source,
                    remedy: 'The settlement tables are not present on this installation. Run `php artisan migrate`.',
                    note: $note,
                )
                : Metric::of(value: $value, source: $source, unit: 'rows', note: $note);
        } catch (Throwable $exception) {
            return Metric::failed($source, $exception);
        }
    }

    private function since(): Carbon
    {
        return Carbon::now()->subHours(self::WINDOW_HOURS);
    }
}
