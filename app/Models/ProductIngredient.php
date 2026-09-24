<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id @property int $product_id @property int $raw_material_id @property string $quantity_required @property \Illuminate\Support\Carbon $created_at @property \Illuminate\Support\Carbon|null $updated_at */
class ProductIngredient extends Model
{
    protected $fillable = ['product_id', 'raw_material_id', 'quantity_required'];

    protected $casts = ['quantity_required' => 'decimal:3', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'raw_material_id');
    }
}
