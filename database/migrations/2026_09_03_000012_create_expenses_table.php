<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name', 150);
            $table->decimal('amount', 10, 2);
            $table->text('note')->nullable();
            $table->date('expense_date');
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
            $table->string('status', 20)->default('ACTIVE');
        });

        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_status_check CHECK (status IN ('ACTIVE', 'CANCELLED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
