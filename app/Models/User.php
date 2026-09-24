<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/** @property int $id @property string $name @property string $username @property string $password_hash @property string $role @property bool $is_active @property \Illuminate\Support\Carbon $created_at @property \Illuminate\Support\Carbon|null $updated_at */
class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    public const ROLE_ADMIN = 'ADMIN';

    public const ROLE_EMPLOYEE = 'EMPLOYEE';

    protected $fillable = ['name', 'username', 'password_hash', 'role', 'is_active'];

    protected $hidden = ['password_hash'];

    protected $casts = ['is_active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function ordersTaken(): HasMany
    {
        return $this->hasMany(Order::class, 'employee_id');
    }

    public function ordersApproved(): HasMany
    {
        return $this->hasMany(Order::class, 'approved_by');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'created_by');
    }

    public function stockTransactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class, 'created_by');
    }
}
