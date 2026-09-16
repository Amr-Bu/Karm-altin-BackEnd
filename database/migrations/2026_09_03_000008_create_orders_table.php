<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('table_id')->constrained('restaurant_tables')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->integer('chairs_count');
            $table->decimal('chair_price', 10, 2);
            $table->decimal('items_subtotal', 10, 2)->default(0);
            $table->decimal('chairs_total', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->decimal('amount_paid', 10, 2)->nullable();
            $table->decimal('change_amount', 10, 2)->nullable();
            $table->string('order_status', 30);
            $table->string('payment_status', 20)->default('UNPAID');
            $table->timestamp('created_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_order_status_check CHECK (order_status IN ('PENDING_APPROVAL', 'APPROVED', 'PREPARING', 'READY', 'CLOSED', 'CANCELLED'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('UNPAID', 'PAID'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
