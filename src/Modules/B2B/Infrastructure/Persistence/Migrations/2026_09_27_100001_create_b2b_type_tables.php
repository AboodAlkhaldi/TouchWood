<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\B2B\Application\Types\GiveEveryStoreTheStartingTypes;

/*
| b2b.md §1.3, §5: the company types and the document types, both tables staff manage — one list of
| each per store (amendment 5) — and, per store, whether its lists are still the starting ones,
| copied in and not yet reviewed by its admins (amendment 6(a)).
|
| Every rule here is also a rule in code (TypeName, TypePosition, the types' deactivate(), the
| repositories' nameTaken), which refuses first; these are the backstop. Staff change the lists from
| step 4: the unique names within a store are refused as TypeNameTaken, decided under the store's
| type-list lock, and every change clears the store's "copied" flag (markReviewed()) — the notice
| itself is drawn on the staff screen in step 7. A type is never deleted, only deactivated and
| activated again (amendment 10(c)).
|
| Every store already open gets the starting lists here; a store opened later — the launch stores,
| which the seeder creates after the migrations, included — gets them from WriteStartingTypes.
*/

return new class extends Migration
{
    public function up(): void
    {
        foreach (['company_types', 'document_types'] as $table) {
            Schema::create("b2b.{$table}", function (Blueprint $blueprint) use ($table) {
                $blueprint->ulid('id')->primary();
                $blueprint->ulid('store_id');
                $blueprint->string('name_ar', 100);
                $blueprint->string('name_en', 100);
                $blueprint->integer('position');
                $blueprint->boolean('is_active')->default(true);
                // How an inactive type shows to new applications (amendment 5): HIDDEN or GREYED.
                $blueprint->string('inactive_display', 16)->nullable();

                if ($table === 'document_types') {
                    $blueprint->boolean('is_required')->default(false);
                }

                $blueprint->timestampsTz();

                // The store whose list it is (amendment 5).
                $blueprint->foreign('store_id')->references('id')->on('platform.stores')->restrictOnDelete();
            });

            foreach (['name_ar', 'name_en'] as $name) {
                // Present, and on one line: a name is a choice in a dropdown.
                DB::statement("ALTER TABLE b2b.{$table} ADD CONSTRAINT {$table}_{$name}_present CHECK (btrim({$name}) <> '')");
                DB::statement("ALTER TABLE b2b.{$table} ADD CONSTRAINT {$table}_{$name}_one_line CHECK ({$name} !~ '[[:cntrl:]]')");
                // Unique ignoring case, in each language, within one store (owner, 2026-09-27;
                // amendment 5): two stores may share a name.
                DB::statement("CREATE UNIQUE INDEX {$table}_{$name}_unique ON b2b.{$table} (store_id, lower({$name}))");
            }

            DB::statement("ALTER TABLE b2b.{$table} ADD CONSTRAINT {$table}_position_range CHECK (position BETWEEN 0 AND 10000)");
            DB::statement("ALTER TABLE b2b.{$table} ADD CONSTRAINT {$table}_inactive_display CHECK (inactive_display IS NULL OR inactive_display IN ('HIDDEN','GREYED'))");
            // An inactive type says how it shows, and only an inactive one does. Never NULL:
            // is_active is NOT NULL, and IS NOT NULL is never NULL.
            DB::statement("ALTER TABLE b2b.{$table} ADD CONSTRAINT {$table}_inactive_display_when_inactive CHECK ((NOT is_active) = (inactive_display IS NOT NULL))");
        }

        Schema::create('b2b.store_type_lists', function (Blueprint $table) {
            $table->ulid('store_id');
            // Set when the starting lists are written into the store; cleared once its admins change
            // a type or mark the lists reviewed. No default: whoever writes the row says which.
            $table->boolean('copied_not_reviewed');
            $table->timestampTz('updated_at');

            $table->foreign('store_id', 'store_type_lists_store')->references('id')->on('platform.stores')->cascadeOnDelete();
        });

        // Named here: Laravel's primary() takes a name that PostgreSQL's grammar then ignores.
        DB::statement('ALTER TABLE b2b.store_type_lists ADD CONSTRAINT store_type_lists_pkey PRIMARY KEY (store_id)');

        // The stores this installation already has start with the same lists; one opened later is
        // served by WriteStartingTypes.
        app(GiveEveryStoreTheStartingTypes::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b.store_type_lists');
        Schema::dropIfExists('b2b.document_types');
        Schema::dropIfExists('b2b.company_types');
    }
};
