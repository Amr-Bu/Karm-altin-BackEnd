<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id @property int $product_id @property string $transaction_type @property string $direction @property string $quantity @property int|null $reference_order_id @property string|null $note @property int $created_by @property \Illuminate\Support\Carbon $created_at */
class StockTransaction extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_PURCHASE = 'PURCHASE';

    public const TYPE_ORDER_CONSUMPTION = 'ORDER_CONSUMPTION';

    public const TYPE_ORDER_RETURN = 'ORDER_RETURN';

    public const TYPE_WASTE = 'WASTE';

    public const TYPE_ADJUSTMENT = 'ADJUSTMENT';

    public const DIRECTION_IN = 'IN';

    public const DIRECTION_OUT = 'OUT';

    protected $fillable = ['product_id', 'transaction_type', 'direction', 'quantity', 'reference_order_id', 'note', 'created_by'];

    protected $casts = ['quantity' => 'decimal:3', 'created_at' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'reference_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
