<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Sync step 5a (sync.md §9.3 #19, the page of reads): each read's lines - every price and stock number it
| changed, from and to - kept with the read and deleted with it after 90 days; each report entry names
| the read that last saw it (no key: the read may be pruned first); who closed an entry is a read,
| unwiring, or a staff member by id.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync.read_lines', function (Blueprint $table) {
            $table->id();
            $table->ulid('read_id');
            $table->string('kind', 8);
            $table->ulid('variant_id');
            $table->string('code', 255)->nullable();
            $table->string('before', 40)->nullable();
            $table->string('after', 40);

            $table->foreign('read_id', 'read_lines_read')->references('id')->on('sync.reads')->cascadeOnDelete();
            $table->index('read_id', 'read_lines_read_idx');
        });

        DB::statement("ALTER TABLE sync.read_lines ADD CONSTRAINT read_lines_kind CHECK (kind IN ('PRICE', 'STOCK'))");

        DB::statement('ALTER TABLE sync.report_entries ADD COLUMN read_id char(26) NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE sync.report_entries
                ADD CONSTRAINT report_entries_closed_by CHECK (closed_by IS NULL OR closed_by IN ('READ', 'UNWIRED') OR closed_by ~ '^STAFF:[0-9a-z]{26}$')
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sync.report_entries DROP CONSTRAINT report_entries_closed_by, DROP COLUMN read_id');
        Schema::dropIfExists('sync.read_lines');
    }
};
