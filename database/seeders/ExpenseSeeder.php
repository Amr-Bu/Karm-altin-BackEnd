<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ExpenseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = DB::table('users')->where('username', 'admin')->value('id');
        $createdAt = now()->subDays(6);
        DB::table('expenses')->insert([
            ['created_by' => $admin, 'name' => 'رواتب الموظفين', 'amount' => 1850, 'note' => 'دفعة أسبوعية للعاملين في الاستراحة', 'expense_date' => now()->subDays(6)->toDateString(), 'created_at' => $createdAt, 'updated_at' => null, 'status' => 'ACTIVE'],
            ['created_by' => $admin, 'name' => 'فاتورة الكهرباء', 'amount' => 420, 'note' => 'استهلاك المطبخ والصالات', 'expense_date' => now()->subDays(5)->toDateString(), 'created_at' => $createdAt->copy()->addDay(), 'updated_at' => null, 'status' => 'ACTIVE'],
            ['created_by' => $admin, 'name' => 'شراء خضار وفواكه', 'amount' => 260, 'note' => 'مشتريات السوق اليومية', 'expense_date' => now()->subDays(3)->toDateString(), 'created_at' => $createdAt->copy()->addDays(3), 'updated_at' => null, 'status' => 'ACTIVE'],
            ['created_by' => $admin, 'name' => 'مواد تنظيف', 'amount' => 145, 'note' => 'منظفات ودورات مياه', 'expense_date' => now()->subDays(1)->toDateString(), 'created_at' => $createdAt->copy()->addDays(5), 'updated_at' => null, 'status' => 'ACTIVE'],
            ['created_by' => $admin, 'name' => 'صيانة مكيف الصالة', 'amount' => 300, 'note' => 'مصروف ألغي بعد إلغاء الخدمة', 'expense_date' => now()->subDays(2)->toDateString(), 'created_at' => $createdAt->copy()->addDays(4), 'updated_at' => null, 'status' => 'CANCELLED'],
        ]);
    }
}