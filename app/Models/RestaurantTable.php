<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id @property int $table_number @property int|null $default_chairs @property string $status @property bool $is_active @property \Illuminate\Support\Carbon $created_at @property \Illuminate\Support\Carbon|null $updated_at */
class RestaurantTable extends Model
{
    public const STATUS_AVAILABLE = 'AVAILABLE';

    public const STATUS_OCCUPIED = 'OCCUPIED';

    protected $fillable = ['table_number', 'default_chairs', 'status', 'is_active'];

    protected $casts = ['table_number' => 'integer', 'default_chairs' => 'integer', 'is_active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'table_id');
    }
}
