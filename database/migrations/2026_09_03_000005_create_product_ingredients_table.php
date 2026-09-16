<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('raw_material_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity_required', 12, 3);
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
            $table->unique(['product_id', 'raw_material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_ingredients');
    }
};
