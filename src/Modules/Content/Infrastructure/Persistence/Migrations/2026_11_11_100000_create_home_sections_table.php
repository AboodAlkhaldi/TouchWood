<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| content.md §1.4, §5: the home page's fixed sections, per store, each type at most once, ordered, shown or
| hidden. `settings` holds the section's words only (no media id: those sit in home_section_media with their
| keys, §1.1 rule 10). `version` for the two-people check (§1.1 rule 12).
|
| A store that already exists gets its eight, hidden, in the starting order (§1.4); a store made later gets them
| from StoreCreated (WriteStartingSections). Every CHECK is the last line: the code refuses first.
*/

return new class extends Migration
{
    /** The starting order (§1.4); kept here as written, so a later change to the code changes no migration. */
    private const array TYPES = ['HERO', 'PROMISES', 'CATEGORIES', 'BEST_SELLERS', 'PROJECTS', 'AUDIENCE', 'POINTS', 'CLIENT_LOGOS'];

    public function up(): void
    {
        Schema::create('content.home_sections', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->string('type', 16);
            $table->integer('position');
            $table->boolean('is_visible')->default(false);
            $table->jsonb('settings')->default('{}');
            $table->integer('version')->default(1);
            $table->ulid('updated_by')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->primary('id', 'home_sections_pkey');
            $table->foreign('store_id', 'home_sections_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->unique(['store_id', 'type'], 'home_sections_type_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content.home_sections
                ADD CONSTRAINT hs_type CHECK (type IN ('HERO', 'PROMISES', 'CATEGORIES', 'BEST_SELLERS', 'PROJECTS', 'AUDIENCE', 'POINTS', 'CLIENT_LOGOS')),
                ADD CONSTRAINT hs_position CHECK (position >= 0),
                ADD CONSTRAINT hs_settings CHECK (jsonb_typeof(settings) = 'object'),
                ADD CONSTRAINT hs_version CHECK (version >= 1)
            SQL);

        $now = now();

        foreach (DB::table('platform.stores')->orderBy('position')->pluck('id') as $storeId) {
            foreach (self::TYPES as $position => $type) {
                DB::table('content.home_sections')->insert([
                    'id' => strtolower((string) Str::ulid()),
                    'store_id' => (string) $storeId,
                    'type' => $type,
                    'position' => $position,
                    'is_visible' => false,
                    'settings' => '{}',
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content.home_sections');
    }
};
