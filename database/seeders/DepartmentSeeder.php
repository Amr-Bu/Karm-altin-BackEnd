<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $createdAt = now()->subDays(11);
        $printers = DB::table('printers')->pluck('id', 'name');
        DB::table('departments')->insert([
            ['name' => 'المطبخ', 'printer_id' => $printers['طابعة المطبخ'], 'is_active' => true, 'created_at' => $createdAt, 'updated_at' => null],
            ['name' => 'البوفيه', 'printer_id' => $printers['طابعة البوفيه'], 'is_active' => true, 'created_at' => $createdAt->copy()->addMinutes(5), 'updated_at' => null],
            ['name' => 'قسم العصائر', 'printer_id' => $printers['طابعة العصائر'], 'is_active' => true, 'created_at' => $createdAt->copy()->addMinutes(10), 'updated_at' => null],
        ]);
    }
}