<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeatureFlag extends Model
{
    protected $fillable = ['key', 'description', 'enabled', 'rollout_percent', 'seller_ids', 'updated_by'];

    protected $casts = [
        'enabled' => 'boolean',
        'rollout_percent' => 'integer',
        'seller_ids' => 'array',
    ];
}
