<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| catalog.md §5.5 (amendment 6): an uploaded file and its page — a product file's names the catalog
| lacks and its products, a store file's items. Nothing here is the catalog itself: products come in
| through the product handlers when the Super Admin brings them in, and a store file only switches
| existing products on.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog.imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('kind', 16);
            $table->ulid('store_id')->nullable();
            $table->string('file_name', 255);
            // Where a product file's zip is kept until its products are brought in.
            $table->string('archive', 255)->nullable();
            $table->string('state', 16);
            $table->string('failure', 2000)->nullable();
            // The staff member who uploaded it, for its page: an admin record, never product data.
            $table->ulid('uploaded_by')->nullable();
            $table->timestampsTz();

            $table->foreign('store_id', 'imports_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->index(['kind', 'created_at'], 'imports_kind_created_idx');
        });

        DB::statement("ALTER TABLE catalog.imports ADD CONSTRAINT imports_kind CHECK (kind IN ('PRODUCTS','STORE_FILL'))");
        DB::statement("ALTER TABLE catalog.imports ADD CONSTRAINT imports_state CHECK (CASE kind WHEN 'PRODUCTS' THEN state IN ('DECIDING','BRINGING_IN','IN','FAILED') ELSE state = 'OPEN' END)");
        DB::statement("ALTER TABLE catalog.imports ADD CONSTRAINT imports_store_for_store_fill CHECK ((kind = 'STORE_FILL') = (store_id IS NOT NULL))");
        DB::statement("ALTER TABLE catalog.imports ADD CONSTRAINT imports_archive_for_products CHECK (archive IS NULL OR kind = 'PRODUCTS')");
        DB::statement("ALTER TABLE catalog.imports ADD CONSTRAINT imports_failure_when_failed CHECK ((state = 'FAILED') = (failure IS NOT NULL))");

        Schema::create('catalog.import_names', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('import_id');
            $table->string('kind', 16);
            // As the file first wrote it — a category as its whole path — and, for "listed once", a
            // SHA-256 of it as names are compared (a path has no length limit; the key has one).
            $table->text('written');
            $table->char('key', 64);
            // A value's attribute, as written; an attribute's job, as the file uses it.
            $table->string('attribute', 100)->nullable();
            $table->string('attribute_kind', 16)->nullable();
            $table->string('decision', 16)->nullable();
            $table->ulid('target_id')->nullable();
            $table->string('name_ar', 100)->nullable();
            $table->string('name_en', 100)->nullable();
            $table->integer('products');

            $table->foreign('import_id', 'import_names_import')->references('id')->on('catalog.imports')->cascadeOnDelete();
            $table->unique(['import_id', 'kind', 'key'], 'import_names_one_per_key');
        });

        DB::statement("ALTER TABLE catalog.import_names ADD CONSTRAINT import_names_kind CHECK (kind IN ('CATEGORY','BRAND','ATTRIBUTE','VALUE','SET','WARRANTY'))");
        DB::statement("ALTER TABLE catalog.import_names ADD CONSTRAINT import_names_decision CHECK (decision IS NULL OR decision IN ('EXISTING','CREATE','REFUSE'))");
        DB::statement("ALTER TABLE catalog.import_names ADD CONSTRAINT import_names_value_attribute CHECK ((kind = 'VALUE') = (attribute IS NOT NULL))");
        DB::statement("ALTER TABLE catalog.import_names ADD CONSTRAINT import_names_attribute_kind CHECK ((kind = 'ATTRIBUTE') = (attribute_kind IS NOT NULL) AND (attribute_kind IS NULL OR attribute_kind IN ('INFORMATIONAL','FILTERABLE','VARIANT')))");
        DB::statement("ALTER TABLE catalog.import_names ADD CONSTRAINT import_names_existing_target CHECK (decision IS DISTINCT FROM 'EXISTING' OR target_id IS NOT NULL)");
        DB::statement("ALTER TABLE catalog.import_names ADD CONSTRAINT import_names_create_names CHECK (CASE WHEN decision = 'CREATE' THEN name_ar IS NOT NULL AND name_en IS NOT NULL ELSE name_ar IS NULL AND name_en IS NULL END)");
        DB::statement("ALTER TABLE catalog.import_names ADD CONSTRAINT import_names_refused_target CHECK (decision IS DISTINCT FROM 'REFUSE' OR target_id IS NULL)");
        DB::statement('ALTER TABLE catalog.import_names ADD CONSTRAINT import_names_products CHECK (products >= 1)');

        Schema::create('catalog.import_products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('import_id');
            $table->integer('number');
            $table->jsonb('data');
            // The product already holding one of its codes, when the file came in.
            $table->ulid('conflict_product_id')->nullable();
            $table->string('decision', 16)->nullable();
            $table->jsonb('new_codes')->nullable();
            $table->ulid('product_id')->nullable();
            $table->string('state', 16);

            $table->foreign('import_id', 'import_products_import')->references('id')->on('catalog.imports')->cascadeOnDelete();
            // A product deleted later leaves the row as the record of what the file held.
            $table->foreign('conflict_product_id', 'import_products_conflict')->references('id')->on('catalog.products')->nullOnDelete();
            $table->foreign('product_id', 'import_products_product')->references('id')->on('catalog.products')->nullOnDelete();
            $table->unique(['import_id', 'number'], 'import_products_one_per_number');
            $table->index('product_id', 'import_products_product_idx');
        });

        DB::statement('ALTER TABLE catalog.import_products ADD COLUMN codes text[] NOT NULL');
        DB::statement("ALTER TABLE catalog.import_products ADD CONSTRAINT import_products_decision CHECK (decision IS NULL OR decision IN ('UPDATE','REPLACE','SKIP','RECODE'))");
        DB::statement("ALTER TABLE catalog.import_products ADD CONSTRAINT import_products_recode CHECK (CASE WHEN decision = 'RECODE' THEN jsonb_typeof(new_codes) = 'object' ELSE new_codes IS NULL END)");
        DB::statement("ALTER TABLE catalog.import_products ADD CONSTRAINT import_products_state CHECK (state IN ('WAITING','IN','UPDATED','REPLACED','SKIPPED','HELD','ACCEPTED','ARCHIVED','DELETED'))");
        DB::statement('ALTER TABLE catalog.import_products ADD CONSTRAINT import_products_number CHECK (number >= 1)');
        DB::statement("ALTER TABLE catalog.import_products ADD CONSTRAINT import_products_data_object CHECK (jsonb_typeof(data) = 'object')");

        Schema::create('catalog.store_fill_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('import_id');
            $table->integer('number');
            $table->string('code', 10);
            // Shown, not kept, until Pricing and Inventory exist (stage 5).
            $table->string('price', 32);
            $table->integer('stock')->nullable();
            $table->string('state', 16);

            $table->foreign('import_id', 'store_fill_items_import')->references('id')->on('catalog.imports')->cascadeOnDelete();
            $table->unique(['import_id', 'number'], 'store_fill_items_one_per_number');
        });

        DB::statement("ALTER TABLE catalog.store_fill_items ADD CONSTRAINT store_fill_items_code CHECK (code ~ '^[0-9]{1,10}\$')");
        DB::statement("ALTER TABLE catalog.store_fill_items ADD CONSTRAINT store_fill_items_state CHECK (state IN ('OPEN','ON','REMOVED'))");
        DB::statement('ALTER TABLE catalog.store_fill_items ADD CONSTRAINT store_fill_items_stock CHECK (stock IS NULL OR stock >= 0)');
        DB::statement('ALTER TABLE catalog.store_fill_items ADD CONSTRAINT store_fill_items_number CHECK (number >= 1)');
    }

    public function down(): void
    {
        foreach (['store_fill_items', 'import_products', 'import_names', 'imports'] as $table) {
            Schema::dropIfExists("catalog.{$table}");
        }
    }
};
