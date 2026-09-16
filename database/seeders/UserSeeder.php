<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $createdAt = now()->subDays(14);
        DB::table('users')->insert([
            ['name' => 'المدير العام', 'username' => 'admin', 'password_hash' => Hash::make('admin123'), 'role' => 'ADMIN', 'is_active' => true, 'created_at' => $createdAt, 'updated_at' => null],
            ['name' => 'أحمد العلي', 'username' => 'ahmed', 'password_hash' => Hash::make('employee123'), 'role' => 'EMPLOYEE', 'is_active' => true, 'created_at' => $createdAt->copy()->addHour(), 'updated_at' => null],
            ['name' => 'خالد حسن', 'username' => 'khaled', 'password_hash' => Hash::make('employee123'), 'role' => 'EMPLOYEE', 'is_active' => true, 'created_at' => $createdAt->copy()->addHours(2), 'updated_at' => null],
            ['name' => 'سارة محمود', 'username' => 'sara', 'password_hash' => Hash::make('employee123'), 'role' => 'EMPLOYEE', 'is_active' => true, 'created_at' => $createdAt->copy()->addHours(3), 'updated_at' => null],
        ]);
    }
}