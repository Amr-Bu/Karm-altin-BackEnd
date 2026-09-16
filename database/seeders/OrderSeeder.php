<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $users = DB::table('users')->pluck('id', 'username');
        $tables = DB::table('restaurant_tables')->pluck('id', 'table_number');
        $base = now()->subDays(7);
        DB::table('orders')->insert([
            ['order_number' => 'ORD-1001', 'employee_id' => $users['ahmed'], 'table_id' => $tables[1], 'approved_by' => $users['admin'], 'chairs_count' => 4, 'chair_price' => 5, 'items_subtotal' => 88, 'chairs_total' => 20, 'total_amount' => 108, 'amount_paid' => 120, 'change_amount' => 12, 'order_status' => 'CLOSED', 'payment_status' => 'PAID', 'created_at' => $base, 'approved_at' => $base->copy()->addMinutes(4), 'paid_at' => $base->copy()->addMinutes(55), 'closed_at' => $base->copy()->addMinutes(60), 'cancelled_at' => null],
            ['order_number' => 'ORD-1002', 'employee_id' => $users['khaled'], 'table_id' => $tables[2], 'approved_by' => $users['admin'], 'chairs_count' => 5, 'chair_price' => 5, 'items_subtotal' => 74, 'chairs_total' => 25, 'total_amount' => 99, 'amount_paid' => null, 'change_amount' => null, 'order_status' => 'PREPARING', 'payment_status' => 'UNPAID', 'created_at' => $base->copy()->addDays(2), 'approved_at' => $base->copy()->addDays(2)->addMinutes(5), 'paid_at' => null, 'closed_at' => null, 'cancelled_at' => null],
            ['order_number' => 'ORD-1003', 'employee_id' => $users['sara'], 'table_id' => $tables[3], 'approved_by' => null, 'chairs_count' => 4, 'chair_price' => 5, 'items_subtotal' => 38, 'chairs_total' => 20, 'total_amount' => 58, 'amount_paid' => null, 'change_amount' => null, 'order_status' => 'PENDING_APPROVAL', 'payment_status' => 'UNPAID', 'created_at' => $base->copy()->addDays(6), 'approved_at' => null, 'paid_at' => null, 'closed_at' => null, 'cancelled_at' => null],
            ['order_number' => 'ORD-1004', 'employee_id' => $users['ahmed'], 'table_id' => $tables[4], 'approved_by' => null, 'chairs_count' => 3, 'chair_price' => 5, 'items_subtotal' => 34, 'chairs_total' => 15, 'total_amount' => 49, 'amount_paid' => null, 'change_amount' => null, 'order_status' => 'CANCELLED', 'payment_status' => 'UNPAID', 'created_at' => $base->copy()->addDays(4), 'approved_at' => null, 'paid_at' => null, 'closed_at' => null, 'cancelled_at' => $base->copy()->addDays(4)->addMinutes(8)],
        ]);
    }
}