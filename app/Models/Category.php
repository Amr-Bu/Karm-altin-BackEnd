<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id @property string $name @property bool $is_active @property \Illuminate\Support\Carbon $created_at @property \Illuminate\Support\Carbon|null $updated_at */
class Category extends Model
{
    protected $fillable = ['name', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
