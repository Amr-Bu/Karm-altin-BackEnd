<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL/MariaDB has no native partial unique index.
        // We simulate it with a generated (virtual/stored) column:
        // active_table_id = table_id when the order is "active", NULL otherwise.
        // MySQL allows multiple NULLs in a UNIQUE index, so this enforces
        // "at most one active order per table" exactly like a partial index would.
        DB::statement("
            ALTER TABLE orders
            ADD COLUMN active_table_id BIGINT UNSIGNED
            GENERATED ALWAYS AS (
                CASE
                    WHEN order_status NOT IN ('CLOSED', 'CANCELLED')
                    THEN table_id
                    ELSE NULL
                END
            ) STORED
        ");

        DB::statement("
            ALTER TABLE orders
            ADD UNIQUE INDEX one_active_order_per_table (active_table_id)
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE orders DROP INDEX one_active_order_per_table");
        DB::statement("ALTER TABLE orders DROP COLUMN active_table_id");
    }
};