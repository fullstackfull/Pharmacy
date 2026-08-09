<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A per-group override of one product's price (Phase 3, Stage E).
 */
class CustomerGroupPrice extends Model
{
    protected $fillable = ['customer_group_id', 'product_id', 'price', 'discount_percent'];

    protected $casts = [
        'customer_group_id' => 'integer',
        'product_id' => 'integer',
        'price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
    ];
}
