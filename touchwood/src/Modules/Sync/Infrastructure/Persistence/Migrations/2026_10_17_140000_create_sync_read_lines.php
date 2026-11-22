<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Sync step 5a (sync.md §9.3 #19, the page of reads): each read's lines - every price and stock number it
| changed, from and to - and what it reported, each as it was that day (owner, 2026-10-18: exact per
| read); both kept with the read and deleted with it after 90 days. Who closed an entry is a read,
| unwiring, Catalog (the size switched on), or a staff member by id.
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

        Schema::create('sync.read_reports', function (Blueprint $table) {
            $table->id();
            $table->ulid('read_id');
            $table->string('kind', 24);
            $table->string('code', 255)->nullable();
            $table->ulid('variant_id')->nullable();
            $table->ulid('sale_id')->nullable();
            $table->jsonb('detail');

            $table->foreign('read_id', 'read_reports_read')->references('id')->on('sync.reads')->cascadeOnDelete();
            $table->index('read_id', 'read_reports_read_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sync.report_entries
                ADD CONSTRAINT report_entries_closed_by CHECK (closed_by IS NULL OR closed_by IN ('READ', 'UNWIRED', 'CATALOG') OR closed_by ~ '^STAFF:[0-9a-z]{26}$')
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sync.report_entries DROP CONSTRAINT report_entries_closed_by');
        Schema::dropIfExists('sync.read_reports');
        Schema::dropIfExists('sync.read_lines');
    }
};
