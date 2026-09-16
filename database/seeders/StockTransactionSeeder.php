<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StockTransactionSeeder extends Seeder
{
    public function run(): void
    {
        $products = DB::table('products')->pluck('id', 'name');
        $users = DB::table('users')->pluck('id', 'username');
        $orders = DB::table('orders')->pluck('id', 'order_number');
        $createdAt = now()->subDays(6);
        $transactions = [
            ['دجاج طازج', 'PURCHASE', 'IN', 10, null, 'توريد دجاج طازج 10 كيلو من المورد'],
            ['أرز بسمتي', 'PURCHASE', 'IN', 5, null, 'توريد أرز بسمتي للمطبخ'],
            ['بطاطا', 'PURCHASE', 'IN', 8, null, 'توريد بطاطا طازجة'],
            ['بهارات مشكلة', 'PURCHASE', 'IN', 1, null, 'توريد بهارات مشكلة'],
            ['زيت نباتي', 'PURCHASE', 'IN', 5, null, 'توريد زيت نباتي'],
            ['خبز عربي', 'PURCHASE', 'IN', 50, null, 'توريد خبز عربي طازج'],
            ['مشروب كولا', 'PURCHASE', 'IN', 24, null, 'توريد مشروبات غازية'],
            ['عصير مانجو', 'PURCHASE', 'IN', 12, null, 'توريد عصائر مانجو'],
            ['مياه معدنية', 'PURCHASE', 'IN', 20, null, 'توريد مياه معدنية'],
            ['دجاج طازج', 'ORDER_CONSUMPTION', 'OUT', 0.500, 'ORD-1001', 'استهلاك مكونات الطلب ORD-1001'],
            ['أرز بسمتي', 'ORDER_CONSUMPTION', 'OUT', 0.360, 'ORD-1001', 'استهلاك مكونات الطلب ORD-1001'],
            ['بهارات مشكلة', 'ORDER_CONSUMPTION', 'OUT', 0.020, 'ORD-1001', 'استهلاك مكونات الطلب ORD-1001'],
            ['زيت نباتي', 'ORDER_CONSUMPTION', 'OUT', 0.040, 'ORD-1001', 'استهلاك مكونات الطلب ORD-1001'],
            ['مشروب كولا', 'ORDER_CONSUMPTION', 'OUT', 2, 'ORD-1001', 'صرف مشروبات الطلب ORD-1001'],
            ['دجاج طازج', 'ORDER_CONSUMPTION', 'OUT', 0.400, 'ORD-1002', 'استهلاك مكونات الطلب ORD-1002'],
            ['خبز عربي', 'ORDER_CONSUMPTION', 'OUT', 2, 'ORD-1002', 'استهلاك مكونات الطلب ORD-1002'],
            ['بهارات مشكلة', 'ORDER_CONSUMPTION', 'OUT', 0.020, 'ORD-1002', 'استهلاك مكونات الطلب ORD-1002'],
            ['زيت نباتي', 'ORDER_CONSUMPTION', 'OUT', 0.030, 'ORD-1002', 'استهلاك مكونات الطلب ORD-1002'],
            ['عصير مانجو', 'ORDER_CONSUMPTION', 'OUT', 1, 'ORD-1002', 'صرف مشروبات الطلب ORD-1002'],
            ['مشروب كولا', 'ORDER_CONSUMPTION', 'OUT', 1, 'ORD-1002', 'صرف مشروبات الطلب ORD-1002'],
        ];
        foreach ($transactions as $offset => [$product, $type, $direction, $quantity, $order, $note]) {
            DB::table('stock_transactions')->insert([
                'product_id' => $products[$product], 'transaction_type' => $type, 'direction' => $direction, 'quantity' => $quantity,
                'reference_order_id' => $order ? $orders[$order] : null, 'note' => $note, 'created_by' => $order ? $users['khaled'] : $users['admin'], 'created_at' => $createdAt->copy()->addMinutes($offset),
            ]);
        }
    }
}