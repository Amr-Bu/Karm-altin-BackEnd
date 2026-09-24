<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id @property int $created_by @property string $name @property string $amount @property string|null $note @property \Illuminate\Support\Carbon $expense_date @property \Illuminate\Support\Carbon $created_at @property \Illuminate\Support\Carbon|null $updated_at @property string $status */
class Expense extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = ['created_by', 'name', 'amount', 'note', 'expense_date', 'status'];

    protected $casts = ['amount' => 'decimal:2', 'expense_date' => 'date', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
