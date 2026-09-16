<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductIngredientSeeder extends Seeder
{
    public function run(): void
    {
        $products = DB::table('products')->pluck('id', 'name');
        $createdAt = now()->subDays(9);
        $recipes = [
            ['كبسة دجاج', 'دجاج طازج', 0.250], ['كبسة دجاج', 'أرز بسمتي', 0.180], ['كبسة دجاج', 'بهارات مشكلة', 0.010], ['كبسة دجاج', 'زيت نباتي', 0.020],
            ['شاورما دجاج', 'دجاج طازج', 0.200], ['شاورما دجاج', 'خبز عربي', 1.000], ['شاورما دجاج', 'بهارات مشكلة', 0.010], ['شاورما دجاج', 'زيت نباتي', 0.015],
            ['كريبسي دجاج', 'دجاج طازج', 0.250], ['كريبسي دجاج', 'بطاطا', 0.200], ['كريبسي دجاج', 'بهارات مشكلة', 0.010], ['كريبسي دجاج', 'زيت نباتي', 0.030],
        ];
        foreach ($recipes as $offset => [$product, $material, $quantity]) {
            DB::table('product_ingredients')->insert(['product_id' => $products[$product], 'raw_material_id' => $products[$material], 'quantity_required' => $quantity, 'created_at' => $createdAt->copy()->addMinutes($offset), 'updated_at' => null]);
        }
    }
}