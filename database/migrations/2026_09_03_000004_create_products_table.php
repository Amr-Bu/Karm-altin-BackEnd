<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('name', 150);
            $table->string('item_type', 30);
            $table->string('unit', 20);
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('min_stock', 12, 3)->nullable();
            $table->boolean('is_stock_tracked')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_item_type_check CHECK (item_type IN ('RAW_MATERIAL', 'PRODUCED_PRODUCT', 'PURCHASED_PRODUCT'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_unit_check CHECK (unit IN ('KG', 'G', 'LITER', 'ML', 'PIECE'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
