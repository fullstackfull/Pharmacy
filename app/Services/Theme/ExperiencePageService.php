<?php

namespace App\Services\Theme;

use App\Models\ExperiencePage;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The pages an experience is made of.
 *
 * Everything here is written so that a shop whose database has not run the page migration yet
 * behaves exactly as it did before: every method is column-guarded and every failure answers
 * "there is no page row", which the callers already treat as "resolve by slug". That is what lets
 * this ship ahead of the UI that uses it.
 *
 * Pages are per theme, because a theme is what gets published, duplicated and rolled back. Two
 * themes may both have a `home` and they are two different pages with two different section lists.
 */
class ExperiencePageService
{
    /** Slug rules: what can safely live in a URL, a cache key and an API path segment. */
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9\-]{0,58}[a-z0-9]$/';

    public function __construct(private readonly ?AuditLogger $audit = null)
    {
    }

    public function isReady(): bool
    {
        try {
            return Schema::hasTable('experience_pages');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Every page of a theme, system pages first, in the merchant's order.
     *
     * @return Collection<int, ExperiencePage>
     */
    public function forTheme(int $themeId, bool $onlyEnabled = false): Collection
    {
        if (!$this->isReady()) {
            return collect();
        }

        return ExperiencePage::query()
            ->where('theme_id', $themeId)
            ->when($onlyEnabled, fn ($query) => $query->enabled())
            ->orderByRaw("CASE WHEN kind = ? THEN 0 ELSE 1 END", [ExperiencePage::KIND_SYSTEM])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** The page a slug names, or null when this theme has no such page. */
    public function find(int $themeId, string $slug): ?ExperiencePage
    {
        if (!$this->isReady()) {
            return null;
        }

        return ExperiencePage::query()
            ->where('theme_id', $themeId)
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Make sure a theme has the pages the engine guarantees.
     *
     * Called when a theme is created and again whenever the builder opens, so a theme that predates
     * this feature — or one restored from an export — gains its pages the first time somebody looks
     * at it, rather than needing a command nobody runs.
     */
    public function ensureSystemPages(Theme $theme): void
    {
        if (!$this->isReady()) {
            return;
        }

        foreach (ExperiencePage::SYSTEM_SLUGS as $order => $slug) {
            ExperiencePage::query()->firstOrCreate(
                [
                    'theme_id' => $theme->id,
                    'channel'  => ExperiencePage::CHANNEL_SHARED,
                    'slug'     => $slug,
                ],
                [
                    'title'      => ucfirst($slug),
                    'kind'       => ExperiencePage::KIND_SYSTEM,
                    'is_enabled' => true,
                    'sort_order' => $order,
                ],
            );
        }
    }

    /**
     * Create a page the merchant asked for.
     *
     * Returns null rather than throwing on a bad slug or a duplicate: this is called from a form,
     * and the caller turns null into a message.
     */
    public function create(Theme $theme, string $title, ?string $slug = null): ?ExperiencePage
    {
        if (!$this->isReady()) {
            return null;
        }

        $slug = $this->normaliseSlug($slug ?: $title);

        if ($slug === null || $this->find($theme->id, $slug) !== null) {
            return null;
        }

        $page = ExperiencePage::create([
            'theme_id'   => $theme->id,
            'channel'    => ExperiencePage::CHANNEL_SHARED,
            'slug'       => $slug,
            'title'      => trim($title) ?: ucfirst($slug),
            'kind'       => ExperiencePage::KIND_CUSTOM,
            'is_enabled' => true,
            'sort_order' => (int) ExperiencePage::where('theme_id', $theme->id)->max('sort_order') + 1,
        ]);

        $this->audit?->record(
            action: 'experience.page_created',
            subject: $page,
            after: ['slug' => $page->slug, 'title' => $page->title],
            context: ['theme_id' => $theme->id],
        );

        return $page;
    }

    /** Rename a page, or switch it on and off. A system page can be renamed but never disabled. */
    public function update(ExperiencePage $page, ?string $title = null, ?bool $enabled = null): bool
    {
        if (!$this->isReady()) {
            return false;
        }

        if ($title !== null && trim($title) !== '') {
            $page->title = trim($title);
        }

        if ($enabled !== null && !$page->isSystem()) {
            $page->is_enabled = $enabled;
        }

        return $page->save();
    }

    /**
     * Delete a custom page and everything placed on it.
     *
     * A system page is refused: home, header and footer are screens the clients ask for by name,
     * and a shop without a home page is a broken shop, not an empty one.
     */
    public function delete(ExperiencePage $page): bool
    {
        if (!$this->isReady() || $page->isSystem()) {
            return false;
        }

        $slug = $page->slug;
        $themeId = $page->theme_id;

        ThemeSection::where('experience_page_id', $page->id)->delete();
        $page->delete();

        $this->audit?->record(
            action: 'experience.page_deleted',
            subject: null,
            before: ['slug' => $slug],
            context: ['theme_id' => $themeId],
        );

        return true;
    }

    /**
     * The page id a section on this slug belongs to, creating nothing.
     *
     * This is the one method the existing builder path needs: when a section is added, it stamps
     * the id beside the slug it was already writing.
     */
    public function idFor(int $themeId, string $slug): ?int
    {
        return $this->find($themeId, $slug)?->id;
    }

    /** A slug that is safe in a URL, a cache key and a path segment — or null. */
    public function normaliseSlug(string $value): ?string
    {
        $slug = Str::slug(trim($value));

        return preg_match(self::SLUG_PATTERN, $slug) === 1 ? $slug : null;
    }
}
