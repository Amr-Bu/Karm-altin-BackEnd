<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PrinterSeeder extends Seeder
{
    public function run(): void
    {
        $createdAt = now()->subDays(12);
        DB::table('printers')->insert([
            ['name' => 'طابعة المطبخ', 'printer_type' => 'DEPARTMENT', 'connection_type' => 'NETWORK', 'connection_config' => '192.168.1.51', 'is_active' => true, 'created_at' => $createdAt, 'updated_at' => null],
            ['name' => 'طابعة البوفيه', 'printer_type' => 'DEPARTMENT', 'connection_type' => 'NETWORK', 'connection_config' => '192.168.1.52', 'is_active' => true, 'created_at' => $createdAt->copy()->addMinutes(3), 'updated_at' => null],
            ['name' => 'طابعة العصائر', 'printer_type' => 'DEPARTMENT', 'connection_type' => 'USB', 'connection_config' => 'USB001', 'is_active' => true, 'created_at' => $createdAt->copy()->addMinutes(6), 'updated_at' => null],
            ['name' => 'طابعة الإيصالات', 'printer_type' => 'RECEIPT', 'connection_type' => 'USB', 'connection_config' => 'USB002', 'is_active' => true, 'created_at' => $createdAt->copy()->addMinutes(9), 'updated_at' => null],
        ]);
    }
}