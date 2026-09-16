<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('printer_id')->constrained('printers')->restrictOnDelete();
            $table->string('job_type', 30);
            $table->string('status', 20)->default('PENDING');
            $table->text('payload');
            $table->integer('attempts')->default(0);
            $table->timestamp('created_at');
            $table->timestamp('printed_at')->nullable();
        });

        DB::statement("ALTER TABLE print_jobs ADD CONSTRAINT print_jobs_job_type_check CHECK (job_type IN ('NEW_ORDER', 'ADDITION', 'MODIFICATION', 'CANCELLATION', 'RECEIPT', 'REPRINT'))");
        DB::statement("ALTER TABLE print_jobs ADD CONSTRAINT print_jobs_status_check CHECK (status IN ('PENDING', 'PRINTED', 'FAILED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};
