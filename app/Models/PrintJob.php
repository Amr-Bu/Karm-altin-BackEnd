<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id @property int $order_id @property int|null $department_id @property int $printer_id @property string $job_type @property string $status @property string $payload @property int $attempts @property \Illuminate\Support\Carbon $created_at @property \Illuminate\Support\Carbon|null $printed_at */
class PrintJob extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_NEW_ORDER = 'NEW_ORDER';

    public const TYPE_ADDITION = 'ADDITION';

    public const TYPE_MODIFICATION = 'MODIFICATION';

    public const TYPE_CANCELLATION = 'CANCELLATION';

    public const TYPE_RECEIPT = 'RECEIPT';

    public const TYPE_REPRINT = 'REPRINT';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PRINTED = 'PRINTED';

    public const STATUS_FAILED = 'FAILED';

    protected $fillable = ['order_id', 'department_id', 'printer_id', 'job_type', 'status', 'payload', 'attempts', 'printed_at'];

    protected $casts = ['attempts' => 'integer', 'created_at' => 'datetime', 'printed_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }
}
