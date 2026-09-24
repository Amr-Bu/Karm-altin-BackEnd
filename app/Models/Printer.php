<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id @property string $name @property string $printer_type @property string $connection_type @property string|null $connection_config @property bool $is_active @property \Illuminate\Support\Carbon $created_at @property \Illuminate\Support\Carbon|null $updated_at */
class Printer extends Model
{
    public const TYPE_DEPARTMENT = 'DEPARTMENT';

    public const TYPE_RECEIPT = 'RECEIPT';

    protected $fillable = ['name', 'printer_type', 'connection_type', 'connection_config', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }
}
