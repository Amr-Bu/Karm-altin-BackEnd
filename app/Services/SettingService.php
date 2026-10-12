<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SettingService
{
    public function list(): Collection
    {
        return Setting::orderBy('key')->get();
    }

    public function update(string $key, string $value): Setting
    {
        return DB::transaction(function () use ($key, $value) {
            $setting = Setting::where('key', $key)->lockForUpdate()->firstOrFail();
            $setting->update(['value' => $value]);

            return $setting;
        }, 3);
    }
}
