<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SettingService
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        return Setting::orderBy('key')->paginate($filters['per_page'] ?? 20)->withQueryString();
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
