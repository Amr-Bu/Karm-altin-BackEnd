<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RestaurantTableSeeder extends Seeder
{
    public function run(): void
    {
        $createdAt = now()->subDays(8);
        foreach ([[1, 6, 'AVAILABLE'], [2, 4, 'OCCUPIED'], [3, 6, 'OCCUPIED'], [4, 8, 'AVAILABLE'], [5, 4, 'AVAILABLE']] as $offset => [$number, $chairs, $status]) {
            DB::table('restaurant_tables')->insert(['table_number' => $number, 'default_chairs' => $chairs, 'status' => $status, 'is_active' => true, 'created_at' => $createdAt->copy()->addMinutes($offset * 3), 'updated_at' => null]);
        }
    }
}