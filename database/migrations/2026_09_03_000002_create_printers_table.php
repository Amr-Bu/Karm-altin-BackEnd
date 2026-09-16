<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('printer_type', 20);
            $table->string('connection_type', 30);
            $table->text('connection_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
        });

        DB::statement("ALTER TABLE printers ADD CONSTRAINT printers_printer_type_check CHECK (printer_type IN ('DEPARTMENT', 'RECEIPT'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('printers');
    }
};
