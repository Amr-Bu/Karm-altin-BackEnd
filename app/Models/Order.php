<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id @property string $order_number @property int $employee_id @property int $table_id @property int|null $approved_by @property int $chairs_count @property string $chair_price @property string $items_subtotal @property string $chairs_total @property string $total_amount @property string|null $amount_paid @property string|null $change_amount @property string $order_status @property string $payment_status @property \Illuminate\Support\Carbon $created_at @property \Illuminate\Support\Carbon|null $approved_at @property \Illuminate\Support\Carbon|null $paid_at @property \Illuminate\Support\Carbon|null $closed_at @property \Illuminate\Support\Carbon|null $cancelled_at */
class Order extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_PREPARING = 'PREPARING';

    public const STATUS_READY = 'READY';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const PAYMENT_UNPAID = 'UNPAID';

    public const PAYMENT_PAID = 'PAID';

    protected $fillable = ['order_number', 'employee_id', 'table_id', 'approved_by', 'chairs_count', 'chair_price', 'items_subtotal', 'chairs_total', 'total_amount', 'amount_paid', 'change_amount', 'order_status', 'payment_status', 'approved_at', 'paid_at', 'closed_at', 'cancelled_at'];

    protected $casts = ['chairs_count' => 'integer', 'chair_price' => 'decimal:2', 'items_subtotal' => 'decimal:2', 'chairs_total' => 'decimal:2', 'total_amount' => 'decimal:2', 'amount_paid' => 'decimal:2', 'change_amount' => 'decimal:2', 'created_at' => 'datetime', 'approved_at' => 'datetime', 'paid_at' => 'datetime', 'closed_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function stockTransactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class, 'reference_order_id');
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }
}
