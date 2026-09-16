<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('subtotal', 10, 2);
            $table->text('note')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
        });

        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_status_check CHECK (status IN ('ACTIVE', 'CANCELLED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
