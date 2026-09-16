<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            CategorySeeder::class,
            PrinterSeeder::class,
            DepartmentSeeder::class,
            ProductSeeder::class,
            ProductIngredientSeeder::class,
            RestaurantTableSeeder::class,
            OrderSeeder::class,
            OrderItemSeeder::class,
            StockTransactionSeeder::class,
            InventoryStockSeeder::class,
            PrintJobSeeder::class,
            ExpenseSeeder::class,
        ]);
    }
}
