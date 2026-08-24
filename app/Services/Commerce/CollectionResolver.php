<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\ProductCollection;
use App\Services\Theme\ContentSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Turns one collection into the products it currently means (Phase 3.1).
 *
 * Deliberately boring at run time: the rules were validated at save, the metrics were computed on
 * the scheduler, so resolution is one indexed query — Product::active(), a handful of WHEREs from
 * the registry, an ORDER BY on a joined summary row. Everything that could be slow already
 * happened somewhere a shopper is not waiting.
 *
 * Every failure path returns an empty list, because the callers already know what that means:
 * the web's SectionReadiness hides the section, the app receives a source it treats as empty, and
 * SectionDataResolver falls back to the section's catalogue source first. A collection can be
 * misconfigured, deleted, disabled or switched off wholesale, and the page still loads (§9, §84).
 */
class CollectionResolver
{
    public function __construct(private readonly CollectionRuleRegistry $registry)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('commerce.enabled', true);
    }

    /** The collection an id names, if it is live and this installation can serve it. */
    public function find(?int $id): ?ProductCollection
    {
        if ($id === null || $id <= 0 || !$this->enabled() || !$this->ready()) {
            return null;
        }

        try {
            return ProductCollection::query()->live()->find($id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return Collection<int, Product> empty on any failure — never an exception, never null
     */
    public function resolve(ProductCollection|int|null $collection, int $limit = 10): Collection
    {
        $collection = $collection instanceof ProductCollection
            ? ($collection->status ? $collection : null)
            : $this->find($collection);

        if ($collection === null || !$this->enabled()) {
            return collect();
        }

        $limit = max(1, min((int) $limit, ContentSource::MAX_LIMIT));

        try {
            $query = Product::active()->with('brand:id,name,slug');
            $query = $this->joinMetrics($query);

            foreach ($collection->ruleRows() as $rule) {
                $query = $this->applyRule($query, $rule);
                if ($query === null) {
                    // A stored rule the registry no longer recognises means the collection no
                    // longer says what the merchant wrote. Serving a broader list than they asked
                    // for is the one wrong answer — serve nothing and let the fallback speak.
                    return collect();
                }
            }

            return $this->applySort($query, $collection->sort_by)
                ->take($limit)
                ->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    /** Whether this installation has the collection tables at all. */
    public function ready(): bool
    {
        try {
            return Schema::hasTable('product_collections');
        } catch (\Throwable) {
            return false;
        }
    }

    // ---------------------------------------------------------------------------------------

    private function joinMetrics(Builder $query): Builder
    {
        // LEFT join + COALESCE: a product with no metrics row is a product with zero recorded
        // engagement — true, and it keeps brand-new products inside every non-metric rule.
        return $query
            ->leftJoin('product_metrics', 'product_metrics.product_id', '=', 'products.id')
            ->select('products.*');
    }

    private function applyRule(Builder $query, array $rule): ?Builder
    {
        $definition = CollectionRuleRegistry::FIELDS[$rule['field'] ?? ''] ?? null;
        $operator = $rule['operator'] ?? '';
        $value = $rule['value'] ?? null;

        if ($definition === null || !in_array($operator, $definition['operators'], true)) {
            return null;
        }

        $column = isset($definition['metric'])
            ? 'product_metrics.' . $definition['metric']
            : 'products.' . $definition['column'];
        $metric = isset($definition['metric']);

        return match ($operator) {
            'equals'                => $this->compare($query, $column, '=', $value, $metric),
            'not_equals'            => $this->compare($query, $column, '!=', $value, $metric),
            'greater_than'          => $this->compare($query, $column, '>', $value, $metric),
            'greater_than_or_equal' => $this->compare($query, $column, '>=', $value, $metric),
            'less_than'             => $this->compare($query, $column, '<', $value, $metric),
            'less_than_or_equal'    => $this->compare($query, $column, '<=', $value, $metric),
            'between'               => is_array($value) && count($value) === 2
                ? ($metric
                    ? $query->whereRaw('COALESCE(' . $column . ', 0) BETWEEN ? AND ?', $value)
                    : $query->whereBetween($column, $value))
                : null,
            'in'                    => is_array($value) ? $query->whereIn($column, $value) : null,
            'not_in'                => is_array($value) ? $query->whereNotIn($column, $value) : null,
            'within_last_days'      => $query->where($column, '>=', now()->subDays((int) $value)),
            default                 => null,
        };
    }

    /**
     * One comparison, metric-aware. Metric columns go through COALESCE so "sales less than 5"
     * includes the product that never sold — the reading a merchant means.
     */
    private function compare(Builder $query, string $column, string $sqlOperator, mixed $value, bool $metric): ?Builder
    {
        if (!is_numeric($value) && !is_bool($value)) {
            return null;
        }

        $value = is_bool($value) ? (int) $value : $value;

        return $metric
            ? $query->whereRaw('COALESCE(' . $column . ', 0) ' . $sqlOperator . ' ?', [$value])
            : $query->where($column, $sqlOperator, $value);
    }

    private function applySort(Builder $query, ?string $sort): Builder
    {
        $sort = $this->registry->isSort($sort) ? $sort : 'sales_30d';

        return match ($sort) {
            'newest'     => $query->orderByDesc('products.id'),
            'price_low'  => $query->orderBy('products.unit_price'),
            'price_high' => $query->orderByDesc('products.unit_price'),
            default      => $query
                ->orderByRaw('COALESCE(product_metrics.' . $sort . ', 0) DESC')
                ->orderByDesc('products.id'),
        };
    }
}
