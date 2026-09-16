<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("
            CREATE TRIGGER check_product_ingredients_before_insert
            BEFORE INSERT ON product_ingredients
            FOR EACH ROW
            BEGIN
                DECLARE product_type VARCHAR(30);
                DECLARE material_type VARCHAR(30);

                SELECT item_type INTO product_type FROM products WHERE id = NEW.product_id;
                SELECT item_type INTO material_type FROM products WHERE id = NEW.raw_material_id;

                IF product_type IS NULL OR product_type != 'PRODUCED_PRODUCT' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'product_id must reference a product with item_type = PRODUCED_PRODUCT';
                END IF;

                IF material_type IS NULL OR material_type != 'RAW_MATERIAL' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'raw_material_id must reference a product with item_type = RAW_MATERIAL';
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER check_product_ingredients_before_update
            BEFORE UPDATE ON product_ingredients
            FOR EACH ROW
            BEGIN
                DECLARE product_type VARCHAR(30);
                DECLARE material_type VARCHAR(30);

                SELECT item_type INTO product_type FROM products WHERE id = NEW.product_id;
                SELECT item_type INTO material_type FROM products WHERE id = NEW.raw_material_id;

                IF product_type IS NULL OR product_type != 'PRODUCED_PRODUCT' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'product_id must reference a product with item_type = PRODUCED_PRODUCT';
                END IF;

                IF material_type IS NULL OR material_type != 'RAW_MATERIAL' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'raw_material_id must reference a product with item_type = RAW_MATERIAL';
                END IF;
            END
        ");
    }

    public function down(): void
    {
        DB::unprepared("DROP TRIGGER IF EXISTS check_product_ingredients_before_insert");
        DB::unprepared("DROP TRIGGER IF EXISTS check_product_ingredients_before_update");
    }
};