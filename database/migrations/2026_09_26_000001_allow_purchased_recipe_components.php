<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Install replacement guards first: even interrupted DDL never leaves an unguarded table.
        $this->install('recipe_components', "'RAW_MATERIAL', 'PURCHASED_PRODUCT'");
        $this->drop('check_product_ingredients');
    }

    public function down(): void
    {
        // Restoring the old rule must not leave incompatible rows or silently delete recipes.
        if (DB::table('product_ingredients')->join('products', 'products.id', '=', 'product_ingredients.raw_material_id')
            ->where('products.item_type', '!=', 'RAW_MATERIAL')->exists()) {
            throw new RuntimeException('Cannot restore raw-only recipes while purchased components are referenced. Remove those recipe references before rolling back.');
        }
        $this->install('check_product_ingredients', "'RAW_MATERIAL'");
        // Close the race between the preflight query and installing the raw-only guards.
        if (DB::table('product_ingredients')->join('products', 'products.id', '=', 'product_ingredients.raw_material_id')
            ->where('products.item_type', '!=', 'RAW_MATERIAL')->exists()) {
            $this->drop('check_product_ingredients');
            throw new RuntimeException('A purchased component was added during rollback. Recipe guards remain on the new rule; retry after removing its reference.');
        }
        $this->drop('recipe_components');
    }

    private function install(string $prefix, string $allowed): void
    {
        foreach (['insert', 'update'] as $event) {
            $name = $prefix.'_before_'.$event;
            if (DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())->where('TRIGGER_NAME', $name)->exists()) {
                continue;
            }
            $sqlEvent = strtoupper($event);
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$sqlEvent} ON product_ingredients
                FOR EACH ROW
                BEGIN
                    DECLARE parent_type VARCHAR(30);
                    DECLARE component_type VARCHAR(30);
                    SELECT item_type INTO parent_type FROM products WHERE id = NEW.product_id;
                    SELECT item_type INTO component_type FROM products WHERE id = NEW.raw_material_id;
                    IF parent_type IS NULL OR parent_type != 'PRODUCED_PRODUCT' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Recipe parent must be PRODUCED_PRODUCT';
                    END IF;
                    IF component_type IS NULL OR component_type NOT IN ({$allowed}) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid recipe component type';
                    END IF;
                END");
        }
    }

    private function drop(string $prefix): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.$prefix.'_before_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS '.$prefix.'_before_update');
    }
};
