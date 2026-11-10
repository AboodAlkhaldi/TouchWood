<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| content.md §1.15, §5, W11: the old site's addresses and where each now goes. The old address is the key, decoded
| and without its trailing slash, compared byte for byte (`COLLATE "C"`); the new one a full shop path. Its visits
| counted after each answer; the last Check Addresses' mark, and the final address a forwarding one ends on.
| Every rule is the code's first (`RedirectRules`, `RedirectPath`); the CHECKs on the nullable marks are written so a
| NULL can't pass (lesson 35). No address holds an empty word or a dot word (`/../`), which a browser would resolve.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content.redirects', function (Blueprint $table) {
            $table->text('old_path')->collation('C');
            $table->text('new_path');
            $table->bigInteger('visits')->default(0);
            $table->timestampTz('last_visit_at')->nullable();
            $table->string('source', 8);
            $table->string('check_result', 16)->nullable();
            $table->text('check_final_path')->nullable();
            $table->timestampTz('checked_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestampsTz();

            $table->primary('old_path', 'redirects_pkey');
        });

        // W11's order: most visited first, then by old address (the list's keyset).
        DB::statement('CREATE INDEX redirects_visits_idx ON content.redirects (visits DESC, old_path)');

        DB::statement(<<<'SQL'
            ALTER TABLE content.redirects
                ADD CONSTRAINT redirects_old CHECK (char_length(old_path) BETWEEN 2 AND 2000 AND left(old_path, 1) = '/' AND right(old_path, 1) <> '/'),
                ADD CONSTRAINT redirects_new CHECK (char_length(new_path) BETWEEN 5 AND 2000 AND new_path ~ '^/[a-z]{2,8}/(ar|en)(/|$)' AND right(new_path, 1) <> '/'),
                ADD CONSTRAINT redirects_visits CHECK (visits >= 0),
                ADD CONSTRAINT redirects_source CHECK (source IN ('HAND', 'SHEET')),
                ADD CONSTRAINT redirects_check CHECK (check_result IS NULL OR check_result IN ('FINE', 'FORWARDS', 'NOT_FOUND', 'STORE_OFF')),
                ADD CONSTRAINT redirects_checked CHECK ((check_result IS NULL) = (checked_at IS NULL)),
                ADD CONSTRAINT redirects_final CHECK (COALESCE(check_result = 'FORWARDS', false) = (check_final_path IS NOT NULL)),
                ADD CONSTRAINT redirects_words CHECK (
                    strpos(old_path, '//') = 0 AND old_path !~ '(^|/)\.\.?(/|$)'
                    AND strpos(new_path, '//') = 0 AND new_path !~ '(^|/)\.\.?(/|$)'
                    AND (check_final_path IS NULL OR (strpos(check_final_path, '//') = 0 AND check_final_path !~ '(^|/)\.\.?(/|$)')))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content.redirects');
    }
};
