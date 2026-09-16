<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InventoryStockSeeder extends Seeder
{
    public function run(): void
    {
        $createdAt = now();
        $products = DB::table('products')->where('is_stock_tracked', true)->get();
        foreach ($products as $product) {
            $in = DB::table('stock_transactions')->where('product_id', $product->id)->where('direction', 'IN')->sum('quantity');
            $out = DB::table('stock_transactions')->where('product_id', $product->id)->where('direction', 'OUT')->sum('quantity');
            DB::table('inventory_stock')->insert(['product_id' => $product->id, 'current_quantity' => $in - $out, 'updated_at' => $createdAt]);
        }
    }
}