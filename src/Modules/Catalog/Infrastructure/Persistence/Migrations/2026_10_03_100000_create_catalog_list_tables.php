<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| catalog.md §5.3 and amendment 1: the shared lists — brands, the category tree with each store's
| order, attributes with their values and sets, labels, warranties and the search word pairs.
|
| Every rule here is also a rule in code (the models and value objects, the handlers under each
| list's lock), which refuses first; these are the backstop, each named so a test can find it and a
| name never runs past PostgreSQL's 63 characters (lesson 95). Names and slugs are stored trimmed;
| uniqueness ignoring letter case is a unique index on lower(), asked the same way by the
| repositories, so the two never disagree about a letter.
|
| Slugs: every slug a brand or a category ever held is a row of its history table, whose primary
| key (locale, slug) is what keeps an old slug from being given to another (§1.1, §9.3 #3).
*/

return new class extends Migration
{
    public function up(): void
    {
        $this->brands();
        $this->categories();
        $this->attributes();
        $this->labels();
        $this->warranties();
        $this->wordPairs();
    }

    public function down(): void
    {
        foreach (['word_pairs', 'warranties', 'labels', 'attribute_set_members', 'attribute_sets', 'attribute_values', 'attributes', 'store_category_ranks', 'category_slugs', 'categories', 'brand_slugs', 'brands'] as $table) {
            Schema::dropIfExists("catalog.{$table}");
        }
    }

    private function brands(): void
    {
        Schema::create('catalog.brands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->jsonb('description_ar')->nullable();
            $table->jsonb('description_en')->nullable();
            $table->ulid('logo_media_id')->nullable();
            $table->char('origin_country', 2)->nullable();
            $table->string('agency_type', 16);
            $table->boolean('is_default')->default(false);
            $table->boolean('show_in_default_listings')->default(true);
            $table->integer('position');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign('logo_media_id', 'brands_logo_media')->references('id')->on('platform.media')->restrictOnDelete();
        });

        $this->names('brands');
        $this->position('brands', 'position');
        DB::statement("ALTER TABLE catalog.brands ADD CONSTRAINT brands_agency_type CHECK (agency_type IN ('HOUSE','EXCLUSIVE_AGENT','DISTRIBUTOR'))");
        DB::statement("ALTER TABLE catalog.brands ADD CONSTRAINT brands_origin_country CHECK (origin_country IS NULL OR origin_country ~ '^[A-Z]{2}$')");
        // A description in both languages or in neither (handoff §5.2).
        DB::statement('ALTER TABLE catalog.brands ADD CONSTRAINT brands_description_both CHECK ((description_ar IS NULL) = (description_en IS NULL))');
        DB::statement("ALTER TABLE catalog.brands ADD CONSTRAINT brands_description_object CHECK (COALESCE(jsonb_typeof(description_ar) = 'object' AND jsonb_typeof(description_en) = 'object', description_ar IS NULL))");
        // Exactly one default (§1.6): at most one here, at least one kept by the handlers and the seed.
        DB::statement('CREATE UNIQUE INDEX brands_one_default ON catalog.brands (is_default) WHERE is_default');
        DB::statement('ALTER TABLE catalog.brands ADD CONSTRAINT brands_default_active CHECK (NOT is_default OR is_active)');

        $this->slugHistory('brand_slugs', 'brand_id', 'brands');
    }

    private function categories(): void
    {
        Schema::create('catalog.categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('parent_id')->nullable();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->ulid('image_media_id')->nullable();
            $table->boolean('is_active')->default(true);
            // Deactivated because the category above went, so activating that one brings it back.
            $table->boolean('deactivated_with_parent')->default(false);
            $table->timestampsTz();

            $table->foreign('image_media_id', 'categories_image_media')->references('id')->on('platform.media')->restrictOnDelete();
            $table->index('parent_id', 'categories_parent_idx');
        });

        // A key to the table itself is added once the table, and so its primary key, exists:
        // inside Schema::create Laravel adds the foreign key before the primary key.
        DB::statement('ALTER TABLE catalog.categories ADD CONSTRAINT categories_parent FOREIGN KEY (parent_id) REFERENCES catalog.categories (id) ON DELETE RESTRICT');

        $this->names('categories');
        DB::statement('ALTER TABLE catalog.categories ADD CONSTRAINT categories_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id)');
        // Only an inactive category can have gone with its parent.
        DB::statement('ALTER TABLE catalog.categories ADD CONSTRAINT categories_with_parent_inactive CHECK (NOT deactivated_with_parent OR NOT is_active)');

        $this->slugHistory('category_slugs', 'category_id', 'categories');

        // Each store's order of its menu (§1.5): a category's place among its siblings, per store.
        Schema::create('catalog.store_category_ranks', function (Blueprint $table) {
            $table->ulid('store_id');
            $table->ulid('category_id');
            $table->integer('rank');

            $table->primary(['store_id', 'category_id'], 'store_category_ranks_pkey');
            $table->foreign('store_id', 'store_category_ranks_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('category_id', 'store_category_ranks_category')->references('id')->on('catalog.categories')->cascadeOnDelete();
        });

        $this->position('store_category_ranks', 'rank');
    }

    private function attributes(): void
    {
        Schema::create('catalog.attributes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->string('kind', 16);
            $table->string('unit_ar', 20)->nullable();
            $table->string('unit_en', 20)->nullable();
            $table->boolean('is_colour')->default(false);
            $table->integer('position');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        $this->names('attributes');
        $this->position('attributes', 'position');
        DB::statement("ALTER TABLE catalog.attributes ADD CONSTRAINT attributes_kind CHECK (kind IN ('INFORMATIONAL','FILTERABLE','VARIANT'))");
        DB::statement("ALTER TABLE catalog.attributes ADD CONSTRAINT attributes_colour_has_values CHECK (NOT is_colour OR kind <> 'INFORMATIONAL')");
        DB::statement('ALTER TABLE catalog.attributes ADD CONSTRAINT attributes_unit_both CHECK ((unit_ar IS NULL) = (unit_en IS NULL))');

        Schema::create('catalog.attribute_values', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('attribute_id');
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->char('swatch', 7)->nullable();
            $table->integer('position');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign('attribute_id', 'attribute_values_attribute')->references('id')->on('catalog.attributes')->cascadeOnDelete();
        });

        $this->names('attribute_values', unique: false);
        $this->position('attribute_values', 'position');
        // "Black" and "black" are one value of an attribute (owner, 2026-10-02).
        DB::statement('CREATE UNIQUE INDEX attribute_values_name_ar_unique ON catalog.attribute_values (attribute_id, lower(name_ar))');
        DB::statement('CREATE UNIQUE INDEX attribute_values_name_en_unique ON catalog.attribute_values (attribute_id, lower(name_en))');
        DB::statement("ALTER TABLE catalog.attribute_values ADD CONSTRAINT attribute_values_swatch CHECK (swatch IS NULL OR swatch ~ '^#[0-9a-f]{6}$')");

        Schema::create('catalog.attribute_sets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        $this->names('attribute_sets');

        Schema::create('catalog.attribute_set_members', function (Blueprint $table) {
            $table->ulid('attribute_set_id');
            $table->ulid('attribute_id');
            $table->integer('position');

            $table->primary(['attribute_set_id', 'attribute_id'], 'attribute_set_members_pkey');
            $table->foreign('attribute_set_id', 'attribute_set_members_set')->references('id')->on('catalog.attribute_sets')->cascadeOnDelete();
            $table->foreign('attribute_id', 'attribute_set_members_attribute')->references('id')->on('catalog.attributes')->restrictOnDelete();
            $table->index('attribute_id', 'attribute_set_members_attribute_idx');
        });
    }

    private function labels(): void
    {
        Schema::create('catalog.labels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name_ar', 30);
            $table->string('name_en', 30);
            $table->string('tone', 16);
            $table->integer('position');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        $this->names('labels');
        $this->position('labels', 'position');
        // Geist Badge's ten variants (amendment 1(e)).
        DB::statement("ALTER TABLE catalog.labels ADD CONSTRAINT labels_tone CHECK (tone IN ('gray','blue','green','amber','red','gray-subtle','blue-subtle','green-subtle','amber-subtle','red-subtle'))");
        // One or two words (amendment 1(f)): the trimmed name split on its spaces.
        foreach (['name_ar', 'name_en'] as $name) {
            DB::statement("ALTER TABLE catalog.labels ADD CONSTRAINT labels_{$name}_words CHECK (array_length(regexp_split_to_array(btrim({$name}), '[[:space:]]+'), 1) <= 2)");
        }
    }

    private function warranties(): void
    {
        Schema::create('catalog.warranties', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->jsonb('terms_ar');
            $table->jsonb('terms_en');
            // Null for life (§1.9).
            $table->smallInteger('period_months')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        $this->names('warranties');
        DB::statement('ALTER TABLE catalog.warranties ADD CONSTRAINT warranties_period CHECK (period_months IS NULL OR period_months BETWEEN 1 AND 600)');
        DB::statement("ALTER TABLE catalog.warranties ADD CONSTRAINT warranties_terms_object CHECK (jsonb_typeof(terms_ar) = 'object' AND jsonb_typeof(terms_en) = 'object')");
    }

    private function wordPairs(): void
    {
        Schema::create('catalog.word_pairs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('word_a', 50);
            $table->string('word_b', 50);
            $table->timestampTz('created_at');
        });

        // Kept in order and never a word with itself: "a < b" says both (§1.11).
        DB::statement('ALTER TABLE catalog.word_pairs ADD CONSTRAINT word_pairs_ordered CHECK (word_a < word_b)');
        DB::statement("ALTER TABLE catalog.word_pairs ADD CONSTRAINT word_pairs_present CHECK (btrim(word_a) <> '' AND btrim(word_b) <> '')");
        DB::statement('CREATE UNIQUE INDEX word_pairs_unique ON catalog.word_pairs (word_a, word_b)');
    }

    /**
     * Names present and on one line; with `unique`, each name unique in its language ignoring case.
     */
    private function names(string $table, bool $unique = false): void
    {
        foreach (['name_ar', 'name_en'] as $name) {
            DB::statement("ALTER TABLE catalog.{$table} ADD CONSTRAINT {$table}_{$name}_present CHECK (btrim({$name}) <> '')");
            DB::statement("ALTER TABLE catalog.{$table} ADD CONSTRAINT {$table}_{$name}_one_line CHECK ({$name} !~ '[[:cntrl:]]')");

            if ($unique) {
                DB::statement("CREATE UNIQUE INDEX {$table}_{$name}_unique ON catalog.{$table} (lower({$name}))");
            }
        }
    }

    private function position(string $table, string $column): void
    {
        DB::statement("ALTER TABLE catalog.{$table} ADD CONSTRAINT {$table}_{$column}_range CHECK ({$column} BETWEEN 0 AND 10000)");
    }

    /**
     * Every slug the row ever held, one per locale current; the primary key keeps a slug — current
     * or old — from being given to another row of the same kind.
     */
    private function slugHistory(string $table, string $owner, string $ownerTable): void
    {
        Schema::create("catalog.{$table}", function (Blueprint $blueprint) use ($table, $owner, $ownerTable) {
            $blueprint->char('locale', 2);
            $blueprint->string('slug', 200);
            $blueprint->ulid($owner);
            $blueprint->boolean('is_current');
            $blueprint->timestampTz('created_at');

            $blueprint->primary(['locale', 'slug'], "{$table}_pkey");
            $blueprint->foreign($owner, "{$table}_owner")->references('id')->on("catalog.{$ownerTable}")->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE catalog.{$table} ADD CONSTRAINT {$table}_locale CHECK (locale IN ('ar','en'))");
        // Latin for en; for ar, Arabic letters, Latin letters and digits — single hyphens between,
        // none at either end.
        DB::statement("ALTER TABLE catalog.{$table} ADD CONSTRAINT {$table}_shape CHECK (slug !~ '(^-|-\$|--|[[:space:][:cntrl:]])' AND (locale <> 'en' OR slug ~ '^[a-z0-9]+(-[a-z0-9]+)*\$'))");
        DB::statement("CREATE UNIQUE INDEX {$table}_one_current ON catalog.{$table} ({$owner}, locale) WHERE is_current");
    }
};
