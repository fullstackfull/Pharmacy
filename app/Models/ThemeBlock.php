<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable child element inside a theme section (slide, footer column, menu link, …).
 */
class ThemeBlock extends Model
{
    protected $fillable = [
        'theme_section_id', 'type', 'sort_order', 'is_visible', 'settings',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'sort_order' => 'integer',
        'settings'   => 'array',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(ThemeSection::class, 'theme_section_id');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }
}
