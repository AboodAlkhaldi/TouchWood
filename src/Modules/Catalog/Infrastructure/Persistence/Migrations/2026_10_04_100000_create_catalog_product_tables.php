<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| catalog.md §5.1 with amendment 3: products and their variants — the product, its slugs and the
| codes its variants ever held, the variants, the value each takes of every attribute of the set, and
| its details-only values.
|
| As in the lists' migration, every rule here is first a rule in code, which refuses first; these are
| the backstop, each named. A product's own rows go with it — which happens only when a draft is
| deleted (§4.1); everything a product points at is RESTRICT.
|
| Codes (amendment 3(e)): `product_codes` holds every code the product's variants ever held, so no
| other product can take one while this product exists; a variant's code must be one of its own
| product's (the composite key), and a value one of its own attribute's (likewise).
*/

return new class extends Migration
{
    public function up(): void
    {
        $this->products();
        $this->slugs();
        $this->codes();
        $this->variants();
    }

    public function down(): void
    {
        foreach (['variant_details', 'variant_values', 'variants', 'product_codes', 'product_slugs', 'products'] as $table) {
            Schema::dropIfExists("catalog.{$table}");
        }

        DB::statement('ALTER TABLE catalog.attribute_values DROP CONSTRAINT IF EXISTS attribute_values_attribute_and_id');
    }

    private function products(): void
    {
        Schema::create('catalog.products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name_ar', 200);
            // A draft may have its Arabic name only (amendment 3(g)).
            $table->string('name_en', 200)->nullable();
            $table->jsonb('description_ar')->nullable();
            $table->jsonb('description_en')->nullable();
            $table->ulid('brand_id');
            $table->ulid('category_id')->nullable();
            $table->ulid('warranty_id')->nullable();
            $table->ulid('attribute_set_id')->nullable();
            $table->string('stage', 16);
            // Set when a deactivation chose "hide" (§1.5, §1.6) — step 4.
            $table->boolean('hidden_by_category')->default(false);
            $table->boolean('hidden_by_brand')->default(false);
            $table->timestampsTz();

            $table->foreign('brand_id', 'products_brand')->references('id')->on('catalog.brands')->restrictOnDelete();
            $table->foreign('category_id', 'products_category')->references('id')->on('catalog.categories')->restrictOnDelete();
            $table->foreign('warranty_id', 'products_warranty')->references('id')->on('catalog.warranties')->restrictOnDelete();
            $table->foreign('attribute_set_id', 'products_attribute_set')->references('id')->on('catalog.attribute_sets')->restrictOnDelete();
            // The lists' "is it in use?" questions.
            $table->index('brand_id', 'products_brand_idx');
            $table->index('category_id', 'products_category_idx');
            $table->index('warranty_id', 'products_warranty_idx');
            $table->index('attribute_set_id', 'products_attribute_set_idx');
        });

        DB::statement("ALTER TABLE catalog.products ADD CONSTRAINT products_stage CHECK (stage IN ('DRAFT','READY','ARCHIVED'))");
        DB::statement("ALTER TABLE catalog.products ADD CONSTRAINT products_name_ar_present CHECK (btrim(name_ar) <> '' AND name_ar !~ '[[:cntrl:]]')");
        DB::statement("ALTER TABLE catalog.products ADD CONSTRAINT products_name_en_present CHECK (name_en IS NULL OR (btrim(name_en) <> '' AND name_en !~ '[[:cntrl:]]'))");
        DB::statement("ALTER TABLE catalog.products ADD CONSTRAINT products_description_object CHECK ((description_ar IS NULL OR jsonb_typeof(description_ar) = 'object') AND (description_en IS NULL OR jsonb_typeof(description_en) = 'object'))");
        // A ready product has a category (§5.1) and an English name (amendment 3(g)). Ready, not "past
        // draft": a draft abandoned is archived as it is (§9.3 #19), and an archived product changes
        // only by being restored — ready again, with both.
        DB::statement("ALTER TABLE catalog.products ADD CONSTRAINT products_category_when_ready CHECK (stage <> 'READY' OR category_id IS NOT NULL)");
        DB::statement("ALTER TABLE catalog.products ADD CONSTRAINT products_english_when_ready CHECK (stage <> 'READY' OR name_en IS NOT NULL)");
    }

    /**
     * Every slug a product ever held (§1.1), one current per locale — the English one only once the
     * product has an English name. As the lists' slug tables, with the product's own rows going with
     * a deleted draft.
     */
    private function slugs(): void
    {
        Schema::create('catalog.product_slugs', function (Blueprint $table) {
            $table->char('locale', 2);
            $table->string('slug', 200);
            $table->ulid('product_id');
            $table->boolean('is_current');
            $table->timestampTz('created_at');

            $table->primary(['locale', 'slug'], 'product_slugs_pkey');
            $table->foreign('product_id', 'product_slugs_owner')->references('id')->on('catalog.products')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE catalog.product_slugs ADD CONSTRAINT product_slugs_locale CHECK (locale IN ('ar','en'))");
        // As the lists' slugs (§5.3): the letters Slug takes, as the ranges themselves — hamza to
        // ghain, feh to yeh, alef wasla to yeh barree (U+0621–063F, 0641–064A, 0671–06D3).
        $arabic = '[ء-ؿف-يٱ-ۓ0-9]+';
        DB::statement('ALTER TABLE catalog.product_slugs ADD CONSTRAINT product_slugs_shape CHECK (CASE locale'
            ." WHEN 'en' THEN slug ~ '^[a-z0-9]+(-[a-z0-9]+)*\$'"
            ." ELSE slug ~ '^{$arabic}(-{$arabic})*\$' END)");
        DB::statement('CREATE UNIQUE INDEX product_slugs_one_current ON catalog.product_slugs (product_id, locale) WHERE is_current');
    }

    private function codes(): void
    {
        Schema::create('catalog.product_codes', function (Blueprint $table) {
            $table->string('code', 10);
            $table->ulid('product_id');
            $table->timestampTz('created_at');

            $table->primary('code', 'product_codes_pkey');
            $table->foreign('product_id', 'product_codes_product')->references('id')->on('catalog.products')->cascadeOnDelete();
            // The variants' composite key points here.
            $table->unique(['product_id', 'code'], 'product_codes_product_code');
        });

        // Digits only, 1 to 10 (amendment 3(e), (j)).
        DB::statement("ALTER TABLE catalog.product_codes ADD CONSTRAINT product_codes_digits CHECK (code ~ '^[0-9]{1,10}\$')");
    }

    private function variants(): void
    {
        Schema::create('catalog.variants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_id');
            $table->string('code', 10);
            // The value ids of the set's attributes, in its order (§5.1).
            $table->string('combination', 600);
            $table->integer('weight_grams')->nullable();
            $table->integer('length_mm')->nullable();
            $table->integer('width_mm')->nullable();
            $table->integer('height_mm')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->integer('position');
            $table->timestampsTz();

            $table->foreign('product_id', 'variants_product')->references('id')->on('catalog.products')->cascadeOnDelete();
            // A code of its own product's, held there: never a code no product holds, never another's.
            // NO ACTION, not RESTRICT: deleting a draft cascades into both tables in one statement,
            // and only NO ACTION waits for the statement's end to check.
            $table->foreign(['product_id', 'code'], 'variants_code_held')->references(['product_id', 'code'])->on('catalog.product_codes');
            $table->unique(['product_id', 'combination'], 'variants_one_per_combination');
            $table->index('code', 'variants_code_idx');
        });

        DB::statement("ALTER TABLE catalog.variants ADD CONSTRAINT variants_code_format CHECK (code ~ '^[0-9]{1,10}\$')");
        DB::statement('ALTER TABLE catalog.variants ADD CONSTRAINT variants_position_range CHECK (position BETWEEN 0 AND 10000)');

        foreach (['weight_grams', 'length_mm', 'width_mm', 'height_mm'] as $column) {
            DB::statement("ALTER TABLE catalog.variants ADD CONSTRAINT variants_{$column}_range CHECK ({$column} IS NULL OR {$column} BETWEEN 1 AND 1000000)");
        }

        // A value is one of its own attribute's: the variants' composite key points at this.
        DB::statement('ALTER TABLE catalog.attribute_values ADD CONSTRAINT attribute_values_attribute_and_id UNIQUE (attribute_id, id)');

        Schema::create('catalog.variant_values', function (Blueprint $table) {
            $table->ulid('variant_id');
            $table->ulid('attribute_id');
            $table->ulid('value_id');

            $table->primary(['variant_id', 'attribute_id'], 'variant_values_pkey');
            $table->foreign('variant_id', 'variant_values_variant')->references('id')->on('catalog.variants')->cascadeOnDelete();
            $table->foreign('attribute_id', 'variant_values_attribute')->references('id')->on('catalog.attributes')->restrictOnDelete();
            $table->foreign(['attribute_id', 'value_id'], 'variant_values_value')->references(['attribute_id', 'id'])->on('catalog.attribute_values')->restrictOnDelete();
            $table->index('value_id', 'variant_values_value_idx');
        });

        Schema::create('catalog.variant_details', function (Blueprint $table) {
            $table->ulid('variant_id');
            $table->ulid('attribute_id');
            $table->string('text_ar', 200)->nullable();
            $table->string('text_en', 200)->nullable();
            $table->decimal('number', 12, 3)->nullable();

            $table->primary(['variant_id', 'attribute_id'], 'variant_details_pkey');
            $table->foreign('variant_id', 'variant_details_variant')->references('id')->on('catalog.variants')->cascadeOnDelete();
            $table->foreign('attribute_id', 'variant_details_attribute')->references('id')->on('catalog.attributes')->restrictOnDelete();
            $table->index('attribute_id', 'variant_details_attribute_idx');
        });

        // Text in both languages, or a number — never both, never one language (§9.3 #13).
        DB::statement('ALTER TABLE catalog.variant_details ADD CONSTRAINT variant_details_one_kind CHECK ((text_ar IS NOT NULL AND text_en IS NOT NULL AND number IS NULL) OR (text_ar IS NULL AND text_en IS NULL AND number IS NOT NULL))');
    }
};
