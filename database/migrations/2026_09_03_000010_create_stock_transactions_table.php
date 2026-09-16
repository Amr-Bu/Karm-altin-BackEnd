<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('transaction_type', 40);
            $table->string('direction', 10);
            $table->decimal('quantity', 12, 3);
            $table->foreignId('reference_order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });

        DB::statement("ALTER TABLE stock_transactions ADD CONSTRAINT stock_transactions_transaction_type_check CHECK (transaction_type IN ('PURCHASE', 'ORDER_CONSUMPTION', 'ORDER_RETURN', 'WASTE', 'ADJUSTMENT'))");
        DB::statement("ALTER TABLE stock_transactions ADD CONSTRAINT stock_transactions_direction_check CHECK (direction IN ('IN', 'OUT'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transactions');
    }
};
