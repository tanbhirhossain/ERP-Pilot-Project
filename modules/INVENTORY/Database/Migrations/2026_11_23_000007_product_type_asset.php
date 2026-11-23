<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asset products: what the fixed-asset purchase flow buys.
     *
     * `type` is a varchar with an inline `in (...)` check — not a native enum — so extending
     * the allowed set means recreating the check, not a column change. The table is rebuilt
     * (create-with-new-check, copy rows, swap) because SQLite cannot alter a check in place and
     * rewriting the table would drop the five foreign keys. Indexes are applied to the new table
     * up front: name lookups, SKU uniqueness and soft-delete filtering are write-hot (catalogue
     * pickers, barcode scans, every active-scope query), so the swap leaves the hot paths — and
     * only the hot paths — covered.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // A swap table from an interrupted run would fail the CREATE below — and its indexes
            // die with it, so dropping it is the whole cleanup.
            DB::statement('DROP TABLE IF EXISTS "inventory_products_new"');
            DB::statement('PRAGMA foreign_keys=OFF');

            DB::statement(<<<'SQL'
                CREATE TABLE "inventory_products_new" (
                    "id" integer primary key autoincrement not null,
                    "name" varchar not null,
                    "sku" varchar not null,
                    "barcode" varchar,
                    "slug" varchar,
                    "category_id" integer,
                    "brand_id" integer,
                    "uom_id" integer,
                    "type" varchar check ("type" in ('stockable', 'consumable', 'service', 'bundle', 'asset')) not null default 'stockable',
                    "description" text,
                    "short_description" varchar,
                    "hs_code" varchar,
                    "weight" numeric,
                    "weight_uom" varchar,
                    "dimensions" varchar,
                    "is_serialized" tinyint(1) not null default '0',
                    "is_batch_tracked" tinyint(1) not null default '0',
                    "shelf_life_days" integer,
                    "reorder_level" numeric not null default '0',
                    "reorder_qty" numeric not null default '0',
                    "valuation_method" varchar check ("valuation_method" in ('weighted', 'fifo')) not null default 'weighted',
                    "cost_price" numeric not null default '0',
                    "selling_price" numeric not null default '0',
                    "mrp" numeric not null default '0',
                    "vat_rate" numeric not null default '0',
                    "currency" varchar not null default 'BDT',
                    "image" varchar,
                    "is_active" tinyint(1) not null default '1',
                    "is_featured" tinyint(1) not null default '0',
                    "status" varchar check ("status" in ('draft', 'active', 'discontinued')) not null default 'active',
                    "created_by" integer,
                    "updated_by" integer,
                    "created_at" datetime,
                    "updated_at" datetime,
                    "deleted_at" datetime,
                    foreign key("category_id") references "inventory_categories"("id") on delete set null,
                    foreign key("brand_id") references "inventory_brands"("id") on delete set null,
                    foreign key("uom_id") references "inventory_uoms"("id") on delete set null,
                    foreign key("created_by") references "users"("id") on delete set null,
                    foreign key("updated_by") references "users"("id") on delete set null
                )
                SQL);

            $indexes = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'inventory_products' AND sql IS NOT NULL");

            // Index names live in one namespace per database, not per table: recreating the old
            // index definitions verbatim would collide with the still-present originals. Each copy
            // is built under a temporary name; once the swap is done the originals are gone with
            // the old table, so the temps are dropped and the saved definitions run verbatim —
            // SQLite has no ALTER INDEX, and the saved SQL already names the right table.
            foreach ($indexes as $index) {
                $temp = $index->name.'__fa7';
                DB::statement(str_replace(
                    ['"'.$index->name.'"', '"inventory_products"'],
                    ['"'.$temp.'"', '"inventory_products_new"'],
                    $index->sql
                ));
            }

            DB::statement('INSERT INTO "inventory_products_new" SELECT * FROM "inventory_products"');
            DB::statement('DROP TABLE "inventory_products"');
            DB::statement('ALTER TABLE "inventory_products_new" RENAME TO "inventory_products"');

            foreach ($indexes as $index) {
                DB::statement('DROP INDEX "'.$index->name.'__fa7"');
            }

            foreach ($indexes as $index) {
                DB::statement($index->sql);
            }

            DB::statement('PRAGMA foreign_keys=ON');
        } else {
            DB::statement("ALTER TABLE inventory_products MODIFY type ENUM('stockable','consumable','service','bundle','asset') NOT NULL DEFAULT 'stockable'");
        }
    }

    public function down(): void
    {
        DB::table('inventory_products')->where('type', 'asset')->update(['type' => 'stockable']);

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP TABLE IF EXISTS "inventory_products_old"');
            DB::statement('PRAGMA foreign_keys=OFF');
            DB::statement(<<<'SQL'
                CREATE TABLE "inventory_products_old" (
                    "id" integer primary key autoincrement not null,
                    "name" varchar not null,
                    "sku" varchar not null,
                    "barcode" varchar,
                    "slug" varchar,
                    "category_id" integer,
                    "brand_id" integer,
                    "uom_id" integer,
                    "type" varchar check ("type" in ('stockable', 'consumable', 'service', 'bundle')) not null default 'stockable',
                    "description" text,
                    "short_description" varchar,
                    "hs_code" varchar,
                    "weight" numeric,
                    "weight_uom" varchar,
                    "dimensions" varchar,
                    "is_serialized" tinyint(1) not null default '0',
                    "is_batch_tracked" tinyint(1) not null default '0',
                    "shelf_life_days" integer,
                    "reorder_level" numeric not null default '0',
                    "reorder_qty" numeric not null default '0',
                    "valuation_method" varchar check ("valuation_method" in ('weighted', 'fifo')) not null default 'weighted',
                    "cost_price" numeric not null default '0',
                    "selling_price" numeric not null default '0',
                    "mrp" numeric not null default '0',
                    "vat_rate" numeric not null default '0',
                    "currency" varchar not null default 'BDT',
                    "image" varchar,
                    "is_active" tinyint(1) not null default '1',
                    "is_featured" tinyint(1) not null default '0',
                    "status" varchar check ("status" in ('draft', 'active', 'discontinued')) not null default 'active',
                    "created_by" integer,
                    "updated_by" integer,
                    "created_at" datetime,
                    "updated_at" datetime,
                    "deleted_at" datetime,
                    foreign key("category_id") references "inventory_categories"("id") on delete set null,
                    foreign key("brand_id") references "inventory_brands"("id") on delete set null,
                    foreign key("uom_id") references "inventory_uoms"("id") on delete set null,
                    foreign key("created_by") references "users"("id") on delete set null,
                    foreign key("updated_by") references "users"("id") on delete set null
                )
                SQL);

            $indexes = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'inventory_products' AND sql IS NOT NULL");

            foreach ($indexes as $index) {
                $temp = $index->name.'__fa7';
                DB::statement(str_replace(
                    ['"'.$index->name.'"', '"inventory_products"'],
                    ['"'.$temp.'"', '"inventory_products_old"'],
                    $index->sql
                ));
            }

            DB::statement('INSERT INTO "inventory_products_old" SELECT * FROM "inventory_products"');
            DB::statement('DROP TABLE "inventory_products"');
            DB::statement('ALTER TABLE "inventory_products_old" RENAME TO "inventory_products"');

            foreach ($indexes as $index) {
                DB::statement('DROP INDEX "'.$index->name.'__fa7"');
            }

            foreach ($indexes as $index) {
                DB::statement($index->sql);
            }

            DB::statement('PRAGMA foreign_keys=ON');
        } else {
            DB::table('inventory_products')->where('type', 'asset')->update(['type' => 'stockable']);
            DB::statement("ALTER TABLE inventory_products MODIFY type ENUM('stockable','consumable','service','bundle') NOT NULL DEFAULT 'stockable'");
        }
    }
};
