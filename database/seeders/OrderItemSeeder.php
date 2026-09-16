<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrderItemSeeder extends Seeder
{
    public function run(): void
    {
        $orders = DB::table('orders')->pluck('id', 'order_number');
        $products = DB::table('products')->pluck('id', 'name');
        $createdAt = now()->subDays(7);
        $items = [
            ['ORD-1001', 'كبسة دجاج', 2, 38, null], ['ORD-1001', 'مشروب كولا', 2, 6, 'بدون ثلج'],
            ['ORD-1002', 'شاورما دجاج', 2, 28, 'زيادة صوص الثوم'], ['ORD-1002', 'عصير مانجو', 1, 12, null], ['ORD-1002', 'مشروب كولا', 1, 6, null],
            ['ORD-1003', 'كريبسي دجاج', 1, 32, 'بدون بصل'], ['ORD-1003', 'مياه معدنية', 2, 3, null],
            ['ORD-1004', 'شاورما دجاج', 1, 28, null], ['ORD-1004', 'مشروب كولا', 1, 6, null],
        ];
        foreach ($items as $offset => [$order, $product, $quantity, $unitPrice, $note]) {
            DB::table('order_items')->insert([
                'order_id' => $orders[$order], 'product_id' => $products[$product], 'quantity' => $quantity, 'unit_price' => $unitPrice,
                'subtotal' => $quantity * $unitPrice, 'note' => $note, 'status' => 'ACTIVE', 'created_at' => $createdAt->copy()->addMinutes($offset * 2), 'updated_at' => null,
            ]);
        }
    }
}