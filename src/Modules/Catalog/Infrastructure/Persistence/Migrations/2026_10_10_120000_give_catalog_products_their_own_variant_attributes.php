<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| catalog.md §1.7, §5.1 (owner, 2026-10-09 and 2026-10-10, amendment 16(b)): no attribute sets. A
| product's variants take their values from any of the library's variant-making attributes, chosen
| in its Variants tab and kept, in order, in `product_attributes` — "Has Variants: Yes" is having at
| least one. Every variant of a product uses the same attributes, and no two have the same values.
|
| Each product's set's members, in the set's order, become its variant attributes; each variant's
| combination is written again in its attributes' id order, so ordering a product's attributes never
| rewrites a variant (P27); then the sets go. A products file not brought in whose names hold a set,
| or whose products name one, **stops the migration, named** (P34): it is brought in or discarded
| first (amendment 10(b)). Nothing is deleted for it; an import already brought in keeps its rows.
*/

return new class extends Migration
{
    public function up(): void
    {
        $problems = [];

        foreach (DB::select(<<<'SQL'
            SELECT DISTINCT i.id, i.file_name
            FROM catalog.imports AS i
            WHERE i.kind = 'PRODUCTS' AND i.state <> 'IN' AND (
                EXISTS (SELECT 1 FROM catalog.import_names AS n WHERE n.import_id = i.id AND n.kind = 'SET')
                OR EXISTS (
                    SELECT 1 FROM catalog.import_products AS p
                    WHERE p.import_id = i.id AND p.state <> 'REFUSED' AND COALESCE(p.edited, p.data)->>'attribute_set' IS NOT NULL
                )
            )
            ORDER BY i.id
            SQL) as $row) {
            $problems[] = "import {$row->id} ({$row->file_name}): it names an attribute set";
        }

        if ($problems !== []) {
            throw new RuntimeException("Attribute sets are gone (catalog.md amendment 16(b)). Bring in or discard these imports first:\n".implode("\n", $problems));
        }

        Schema::create('catalog.product_attributes', function (Blueprint $table) {
            $table->ulid('product_id');
            $table->ulid('attribute_id');
            $table->integer('position');

            $table->primary(['product_id', 'attribute_id'], 'product_attributes_pkey');
            $table->foreign('product_id', 'product_attributes_product')->references('id')->on('catalog.products')->cascadeOnDelete();
            $table->foreign('attribute_id', 'product_attributes_attribute')->references('id')->on('catalog.attributes')->restrictOnDelete();
            $table->index('attribute_id', 'product_attributes_attribute_idx');
        });

        DB::statement('ALTER TABLE catalog.product_attributes ADD CONSTRAINT product_attributes_position_range CHECK (position BETWEEN 1 AND 10000)');

        DB::statement(<<<'SQL'
            INSERT INTO catalog.product_attributes (product_id, attribute_id, position)
            SELECT p.id, m.attribute_id, row_number() OVER (PARTITION BY p.id ORDER BY m.position, m.attribute_id)
            FROM catalog.products AS p
            JOIN catalog.attribute_set_members AS m ON m.attribute_set_id = p.attribute_set_id
            SQL);

        DB::statement(<<<'SQL'
            UPDATE catalog.variants AS v
            SET combination = COALESCE((SELECT string_agg(vv.value_id, ',' ORDER BY vv.attribute_id COLLATE "C") FROM catalog.variant_values AS vv WHERE vv.variant_id = v.id), '')
            SQL);

        // Its key and its index go with it.
        DB::statement('ALTER TABLE catalog.products DROP COLUMN attribute_set_id');

        Schema::dropIfExists('catalog.attribute_set_members');
        Schema::dropIfExists('catalog.attribute_sets');
    }

    /**
     * The tables as they were, empty: the products' sets cannot be told from their attributes.
     */
    public function down(): void
    {
        Schema::create('catalog.attribute_sets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        Schema::create('catalog.attribute_set_members', function (Blueprint $table) {
            $table->ulid('attribute_set_id');
            $table->ulid('attribute_id');
            $table->integer('position');
            $table->primary(['attribute_set_id', 'attribute_id'], 'attribute_set_members_pkey');
            $table->foreign('attribute_set_id', 'attribute_set_members_set')->references('id')->on('catalog.attribute_sets')->cascadeOnDelete();
            $table->foreign('attribute_id', 'attribute_set_members_attribute')->references('id')->on('catalog.attributes')->restrictOnDelete();
        });
        Schema::table('catalog.products', function (Blueprint $table) {
            $table->ulid('attribute_set_id')->nullable();
            $table->foreign('attribute_set_id', 'products_attribute_set')->references('id')->on('catalog.attribute_sets')->restrictOnDelete();
        });
        Schema::dropIfExists('catalog.product_attributes');
    }
};
