<?php

namespace App\Services\Theme;

/**
 * WHAT a section shows, separated from HOW it shows it.
 *
 * These two questions live in one settings bag today: `source`, `source_id`, `limit` and
 * `product_ids` sit beside `style`, `columns` and `gap`, and every consumer digs the ones it wants
 * out by hand. Three places already do that digging — the source map that builds the app's
 * endpoint, the data resolver that runs the query for the web, and the readiness check that asks
 * whether a section has anything to show — and each of them re-implements the same defaults.
 *
 * This is that reading, done once. It is deliberately a value object over the EXISTING keys rather
 * than a new column: every published section already carries them, so nothing needs migrating for
 * this to be true, and the day the builder writes a `content_source` column instead, only
 * {@see fromSettings()} changes.
 *
 * Presentation is not here on purpose. A source that knew about columns and gaps would be the same
 * mixed bag under a better name.
 */
final class ContentSource
{
    /** Sources that name a taxonomy the merchant picked. */
    public const SCOPED = ['category', 'brand'];

    /** The catalogue orderings a product section can ask for. */
    public const KINDS = [
        'featured', 'best_selling', 'new_arrival', 'top_rated', 'category', 'brand', 'manual',
    ];

    public const DEFAULT_KIND = 'featured';

    /** What a section may ask for at once, so no configuration can query the whole catalogue. */
    public const MAX_LIMIT = 40;

    /**
     * @param  array<int, int>  $ids  hand-picked records, in the merchant's order
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?int $id,
        public readonly array $ids,
        public readonly int $limit,
    ) {
    }

    /**
     * Read a section's source out of the settings it is stored in.
     *
     * Every fallback here is the one the callers already applied individually — `featured` when no
     * source was chosen, and each caller's own item count when no limit was set — so this changes
     * no behaviour; it just stops three files from each having their own copy of it.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function fromSettings(array $settings, int $defaultLimit = 10): self
    {
        $kind = is_string($settings['source'] ?? null) ? $settings['source'] : self::DEFAULT_KIND;

        if (!in_array($kind, self::KINDS, true)) {
            $kind = self::DEFAULT_KIND;
        }

        return new self(
            kind: $kind,
            id: self::identifier($settings['source_id'] ?? null),
            ids: self::pickedIds($settings['product_ids'] ?? null),
            limit: self::boundedLimit($settings['limit'] ?? $defaultLimit),
        );
    }

    /**
     * A source fixed to one taxonomy — what a showcase is.
     *
     * A showcase's subject is its identity, not a dropdown: it names the category or brand in its
     * own settings key and has no `source` at all. Building it explicitly is what keeps a stray
     * key in a stored settings bag from quietly turning a category showcase into a featured rail.
     */
    public static function scoped(string $kind, mixed $id, mixed $limit = null): self
    {
        return new self(
            kind: in_array($kind, self::SCOPED, true) ? $kind : self::DEFAULT_KIND,
            id: self::identifier($id),
            ids: [],
            limit: self::boundedLimit($limit),
        );
    }

    /** A source naming exactly these records, in this order — the `manual` case, built directly. */
    public static function picked(string|array|null $ids, ?int $limit = null): self
    {
        return new self(
            kind: 'manual',
            id: null,
            ids: self::pickedIds($ids),
            limit: self::boundedLimit($limit),
        );
    }

    /** Whether this source points at a taxonomy the merchant still has to choose. */
    public function needsSubject(): bool
    {
        return in_array($this->kind, self::SCOPED, true) && $this->id === null;
    }

    /** Whether this source names records rather than an ordering. */
    public function isManual(): bool
    {
        return $this->kind === 'manual';
    }

    /**
     * The merchant's hand-picked ids, in their order.
     *
     * Accepts the comma string a form posts and the array a cast returns, because both reach this
     * code, and drops anything that is not a positive id.
     *
     * @return array<int, int>
     */
    public static function pickedIds(string|array|null $picked): array
    {
        $ids = is_array($picked) ? $picked : explode(',', (string) $picked);

        return array_values(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0));
    }

    private static function identifier(mixed $id): ?int
    {
        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    private static function boundedLimit(mixed $limit): int
    {
        $limit = is_numeric($limit) ? (int) $limit : 10;

        return max(1, min(self::MAX_LIMIT, $limit));
    }
}
