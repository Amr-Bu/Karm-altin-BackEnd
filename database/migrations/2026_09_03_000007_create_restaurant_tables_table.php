<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->integer('table_number')->unique();
            $table->integer('default_chairs')->nullable();
            $table->string('status', 20)->default('AVAILABLE');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
        });

        DB::statement("ALTER TABLE restaurant_tables ADD CONSTRAINT restaurant_tables_status_check CHECK (status IN ('AVAILABLE', 'OCCUPIED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_tables');
    }
};
