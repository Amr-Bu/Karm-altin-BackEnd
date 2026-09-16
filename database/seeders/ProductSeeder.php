<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $createdAt = now()->subDays(10);
        $categories = DB::table('categories')->pluck('id', 'name');
        $departments = DB::table('departments')->pluck('id', 'name');
        $rows = [
            ['name' => 'دجاج طازج', 'item_type' => 'RAW_MATERIAL', 'unit' => 'KG', 'price' => null, 'min_stock' => 5],
            ['name' => 'أرز بسمتي', 'item_type' => 'RAW_MATERIAL', 'unit' => 'KG', 'price' => null, 'min_stock' => 3],
            ['name' => 'بطاطا', 'item_type' => 'RAW_MATERIAL', 'unit' => 'KG', 'price' => null, 'min_stock' => 4],
            ['name' => 'بهارات مشكلة', 'item_type' => 'RAW_MATERIAL', 'unit' => 'KG', 'price' => null, 'min_stock' => 0.5],
            ['name' => 'زيت نباتي', 'item_type' => 'RAW_MATERIAL', 'unit' => 'LITER', 'price' => null, 'min_stock' => 2],
            ['name' => 'خبز عربي', 'item_type' => 'RAW_MATERIAL', 'unit' => 'PIECE', 'price' => null, 'min_stock' => 10],
            ['name' => 'كبسة دجاج', 'item_type' => 'PRODUCED_PRODUCT', 'unit' => 'PIECE', 'price' => 38, 'category_id' => $categories['أطباق شرقية'], 'department_id' => $departments['المطبخ'], 'min_stock' => 0],
            ['name' => 'شاورما دجاج', 'item_type' => 'PRODUCED_PRODUCT', 'unit' => 'PIECE', 'price' => 28, 'category_id' => $categories['وجبات سريعة'], 'department_id' => $departments['المطبخ'], 'min_stock' => 0],
            ['name' => 'كريبسي دجاج', 'item_type' => 'PRODUCED_PRODUCT', 'unit' => 'PIECE', 'price' => 32, 'category_id' => $categories['وجبات سريعة'], 'department_id' => $departments['المطبخ'], 'min_stock' => 0],
            ['name' => 'مشروب كولا', 'item_type' => 'PURCHASED_PRODUCT', 'unit' => 'PIECE', 'price' => 6, 'category_id' => $categories['مشروبات غازية'], 'department_id' => $departments['البوفيه'], 'min_stock' => 6],
            ['name' => 'عصير مانجو', 'item_type' => 'PURCHASED_PRODUCT', 'unit' => 'PIECE', 'price' => 12, 'category_id' => $categories['عصائر طبيعية'], 'department_id' => $departments['قسم العصائر'], 'min_stock' => 4],
            ['name' => 'مياه معدنية', 'item_type' => 'PURCHASED_PRODUCT', 'unit' => 'PIECE', 'price' => 3, 'category_id' => $categories['مشروبات غازية'], 'department_id' => $departments['البوفيه'], 'min_stock' => 10],
        ];
        foreach ($rows as $offset => $row) {
            DB::table('products')->insert(array_merge($row, [
                'category_id' => $row['category_id'] ?? null, 'department_id' => $row['department_id'] ?? null,
                'is_stock_tracked' => true, 'is_active' => true, 'created_at' => $createdAt->copy()->addMinutes($offset * 4), 'updated_at' => null,
            ]));
        }
    }
}