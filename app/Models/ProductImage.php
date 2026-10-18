<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id @property int $product_id @property string $image_path */
class ProductImage extends Model
{
    protected $fillable = ['image_path'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
