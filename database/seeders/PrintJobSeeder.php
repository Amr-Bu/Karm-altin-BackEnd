<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PrintJobSeeder extends Seeder
{
    public function run(): void
    {
        $orders = DB::table('orders')->pluck('id', 'order_number');
        $departments = DB::table('departments')->pluck('id', 'name');
        $printers = DB::table('printers')->pluck('id', 'name');
        $base = now()->subDays(6);
        $jobs = [
            ['ORD-1001', 'المطبخ', 'طابعة المطبخ', 'طلب ORD-1001 - طاولة 1\nكبسة دجاج × 2', 'PRINTED', 1],
            ['ORD-1001', 'البوفيه', 'طابعة البوفيه', 'طلب ORD-1001 - طاولة 1\nمشروب كولا × 2', 'PRINTED', 1],
            ['ORD-1002', 'المطبخ', 'طابعة المطبخ', 'طلب ORD-1002 - طاولة 2\nشاورما دجاج × 2', 'PENDING', 0],
            ['ORD-1002', 'البوفيه', 'طابعة البوفيه', 'طلب ORD-1002 - طاولة 2\nمشروب كولا × 1', 'PRINTED', 1],
        ];
        foreach ($jobs as $offset => [$order, $department, $printer, $payload, $status, $attempts]) {
            DB::table('print_jobs')->insert(['order_id' => $orders[$order], 'department_id' => $departments[$department], 'printer_id' => $printers[$printer], 'job_type' => 'NEW_ORDER', 'status' => $status, 'payload' => $payload, 'attempts' => $attempts, 'created_at' => $base->copy()->addMinutes($offset * 4), 'printed_at' => $status === 'PRINTED' ? $base->copy()->addMinutes($offset * 4 + 1) : null]);
        }
        DB::table('print_jobs')->insert(['order_id' => $orders['ORD-1001'], 'department_id' => null, 'printer_id' => $printers['طابعة الإيصالات'], 'job_type' => 'RECEIPT', 'status' => 'PRINTED', 'payload' => 'إيصال ORD-1001\nالإجمالي: 108\nالمدفوع: 120\nالباقي: 12', 'attempts' => 1, 'created_at' => $base->copy()->addMinutes(20), 'printed_at' => $base->copy()->addMinutes(21)]);
    }
}