<?php

namespace App\Services\SellerIntelligence;

use App\Models\SellerInsight;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Runs the producers and keeps the insight store honest.
 *
 * The contract with a producer is deliberately simple: tell me everything this seller should be
 * warned about right now. Whatever it does not mention, this engine marks resolved. That means a
 * producer never has to remember what it said last time, and — more importantly — a warning
 * disappears by itself the moment the seller fixes what caused it. An alert that has to be dismissed
 * after it stops being true is how alert lists become wallpaper.
 */
class SellerInsightEngine
{
    /** @var array<int, InsightProducer> */
    private array $producers;

    /**
     * @param  iterable<InsightProducer>  $producers
     */
    public function __construct(iterable $producers = [])
    {
        $this->producers = $producers instanceof \Traversable ? iterator_to_array($producers) : $producers;
    }

    /** @return array<int, InsightProducer> */
    public function producers(): array
    {
        return $this->producers;
    }

    /**
     * Recompute one seller's insights.
     *
     * @param  array<int, string>|null  $only  limit to these types, for a targeted refresh after an event
     * @return array{written: int, resolved: int}
     */
    public function refresh(int|string $sellerId, ?array $only = null): array
    {
        if (!Schema::hasTable('seller_insights')) {
            return ['written' => 0, 'resolved' => 0];
        }

        $written = 0;
        $resolved = 0;

        foreach ($this->producers as $producer) {
            if ($only !== null && !in_array($producer->type(), $only, true)) {
                continue;
            }

            $seen = [];

            foreach ($producer->produce($sellerId) as $draft) {
                if (!$this->isValid($draft, $producer)) {
                    continue;
                }

                $seen[] = $draft->fingerprint();
                $this->store($draft);
                $written++;
            }

            $resolved += $this->resolveMissing($sellerId, $producer->type(), $seen);
        }

        return ['written' => $written, 'resolved' => $resolved];
    }

    /**
     * The seller's live insights, worst first.
     *
     * Ordered by the severity map rather than by the stored word, because alphabetically "critical"
     * sorts after "high" and the most urgent thing would land second.
     *
     * @param  array<int, string>|null  $types
     * @return Collection<int, SellerInsight>
     */
    public function open(int|string $sellerId, ?array $types = null, ?string $severity = null, int $limit = 50): Collection
    {
        if (!Schema::hasTable('seller_insights')) {
            return collect();
        }

        return SellerInsight::query()
            ->forSeller($sellerId)
            ->open()
            ->when($types, fn ($query, $only) => $query->whereIn('type', $only))
            ->when($severity, fn ($query, $level) => $query->where('severity', $level))
            ->get()
            // Sorted with an explicit comparator. `sortBy` given a list of bare callables does not
            // multi-sort — it treats them as one key — which put a medium insight above a high one.
            ->sort(function (SellerInsight $a, SellerInsight $b) {
                $rank = (SellerInsight::SEVERITY_ORDER[$a->severity] ?? 99)
                    <=> (SellerInsight::SEVERITY_ORDER[$b->severity] ?? 99);

                // Same severity: newest first, so a fresh warning is not buried under old ones.
                return $rank !== 0 ? $rank : $b->id <=> $a->id;
            })
            ->take($limit)
            ->values();
    }

    /** How many open insights this seller has, by severity — what a home badge needs. */
    public function counts(int|string $sellerId): array
    {
        $counts = array_fill_keys(array_keys(SellerInsight::SEVERITY_ORDER), 0);

        if (!Schema::hasTable('seller_insights')) {
            return $counts + ['total' => 0];
        }

        foreach (SellerInsight::forSeller($sellerId)->open()->get(['severity']) as $insight) {
            if (array_key_exists($insight->severity, $counts)) {
                $counts[$insight->severity]++;
            }
        }

        return $counts + ['total' => array_sum($counts)];
    }

    /**
     * The seller says they do not want to see this one.
     *
     * Critical insights cannot be dismissed: a seller may choose not to act on a suggestion, but not
     * to hide that their account is at risk. Returns false when the insight is not theirs, which is
     * the same answer as "no such insight" — an id is not a way to discover other sellers' rows.
     */
    public function dismiss(int|string $sellerId, int|string $insightId): bool
    {
        $insight = SellerInsight::forSeller($sellerId)->whereKey($insightId)->first();

        if (!$insight || $insight->severity === SellerInsight::SEVERITY_CRITICAL) {
            return false;
        }

        $insight->forceFill(['dismissed_at' => now()])->save();

        return true;
    }

    /**
     * A draft has to be addressed, typed by its own producer and carry a severity we recognise.
     *
     * A producer that gets this wrong is a bug, and a bug here would put an unsortable or
     * unattributable row in front of a seller.
     */
    private function isValid(InsightDraft $draft, InsightProducer $producer): bool
    {
        return $draft->type === $producer->type()
            && $draft->title !== ''
            && in_array($draft->severity, InsightDraft::severities(), true);
    }

    private function store(InsightDraft $draft): void
    {
        SellerInsight::updateOrCreate(
            ['fingerprint' => $draft->fingerprint()],
            $draft->attributes() + ['resolved_at' => null],
        );
    }

    /**
     * Anything this producer used to say and no longer does has stopped being true.
     *
     * @param  array<int, string>  $seen
     */
    private function resolveMissing(int|string $sellerId, string $type, array $seen): int
    {
        return SellerInsight::forSeller($sellerId)
            ->where('type', $type)
            ->whereNull('resolved_at')
            ->when($seen !== [], fn ($query) => $query->whereNotIn('fingerprint', $seen))
            ->update(['resolved_at' => now()]);
    }
}
