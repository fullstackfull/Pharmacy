<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A configurable section on a themed page (hero, product slider, category grid, …).
 */
class ThemeSection extends Model
{
    protected $fillable = [
        'theme_version_id', 'uuid', 'page', 'type', 'sort_order', 'is_visible', 'settings',
        'starts_at', 'ends_at', 'platforms', 'audience',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'sort_order' => 'integer',
        'settings'   => 'array',
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
        'platforms'  => 'array',
        'audience'   => 'array',
    ];

    protected static function booted(): void
    {
        // Every section gets an identity that survives being copied into another version, so a
        // client can hold per-section state across publishes. Assigned here rather than in the
        // builder service because sections are also created by import, presets and the seeder.
        // Guarded on the column so code deployed ahead of the migration — and test schemas built
        // by hand — can still insert; a missing uuid only costs cross-publish identity.
        static::creating(function (self $section) {
            if ($section->uuid === null
                && \Illuminate\Support\Facades\Schema::hasColumn($section->getTable(), 'uuid')) {
                $section->uuid = (string) Str::uuid();
            }
        });
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ThemeVersion::class, 'theme_version_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(ThemeBlock::class)->orderBy('sort_order');
    }

    /**
     * The identity and delivery-rule attributes a copy should carry, limited to the columns that
     * actually exist — the copy paths run against test schemas and mid-migration databases too.
     *
     * @return array<string, mixed>
     */
    public function copyableDeliveryRules(bool $keepUuid): array
    {
        $attributes = [];

        if ($keepUuid && \Illuminate\Support\Facades\Schema::hasColumn($this->getTable(), 'uuid')) {
            $attributes['uuid'] = $this->uuid;
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn($this->getTable(), 'starts_at')) {
            $attributes['starts_at'] = $this->starts_at;
            $attributes['ends_at']   = $this->ends_at;
            $attributes['platforms'] = $this->platforms;
            $attributes['audience']  = $this->audience;
        }

        return $attributes;
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    public function scopeForPage(Builder $query, string $page): Builder
    {
        return $query->where('page', $page);
    }
}
