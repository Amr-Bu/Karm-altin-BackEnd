<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id @property int $order_id @property int $product_id @property string $quantity @property string $unit_price @property string $subtotal @property string|null $note @property string $status @property \Illuminate\Support\Carbon $created_at @property \Illuminate\Support\Carbon|null $updated_at */
class OrderItem extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = ['order_id', 'product_id', 'quantity', 'unit_price', 'subtotal', 'note', 'status'];

    protected $casts = ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'subtotal' => 'decimal:2', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
