<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $createdAt = now()->subDays(13);
        DB::table('categories')->insert([
            ['name' => 'أطباق شرقية', 'is_active' => true, 'created_at' => $createdAt, 'updated_at' => null],
            ['name' => 'وجبات سريعة', 'is_active' => true, 'created_at' => $createdAt->copy()->addMinutes(5), 'updated_at' => null],
            ['name' => 'مشروبات غازية', 'is_active' => true, 'created_at' => $createdAt->copy()->addMinutes(10), 'updated_at' => null],
            ['name' => 'عصائر طبيعية', 'is_active' => true, 'created_at' => $createdAt->copy()->addMinutes(15), 'updated_at' => null],
        ]);
    }
}