<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id @property string $name @property int|null $printer_id @property bool $is_active @property \Illuminate\Support\Carbon $created_at @property \Illuminate\Support\Carbon|null $updated_at */
class Department extends Model
{
    protected $fillable = ['name', 'printer_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }
}
