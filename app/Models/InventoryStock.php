<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id @property int $product_id @property string $current_quantity @property \Illuminate\Support\Carbon $updated_at */
class InventoryStock extends Model
{
    public const CREATED_AT = null;

    protected $table = 'inventory_stock';

    protected $fillable = ['product_id', 'current_quantity'];

    protected $casts = ['current_quantity' => 'decimal:3', 'updated_at' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
