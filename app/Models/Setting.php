<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $value
 */
class Setting extends Model
{
    public const CREATED_AT = null;

    protected $fillable = ['key', 'value'];
}
