<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| sync.md §5: a store's connection to its provider (the key and the notification secret encrypted with
| the app's key - owner, 2026-10-13), the log of each read, and the report's entries. A store is never
| deleted from under its connection, nor from under its reads or report entries. CHECKs on nullable
| columns are written so a NULL cannot slip through (lesson 162).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync.connections', function (Blueprint $table) {
            $table->ulid('store_id')->primary();
            $table->string('provider', 16);
            $table->string('address', 500);
            $table->jsonb('settings');
            $table->text('key_encrypted');
            $table->text('secret_encrypted');
            $table->string('state', 16);
            $table->timestampTz('last_read_at')->nullable();
            $table->timestampTz('last_full_read_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->ulid('created_by');
            // Not timestampsTz(): Laravel makes those two nullable, and every connection has both.
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->foreign('store_id', 'connections_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sync.connections
                ADD CONSTRAINT connections_provider CHECK (provider IN ('ODOO')),
                ADD CONSTRAINT connections_state CHECK (state IN ('OK', 'FAILING')),
                ADD CONSTRAINT connections_address CHECK (address ~ '^https://'),
                ADD CONSTRAINT connections_settings CHECK (jsonb_typeof(settings) = 'object'),
                -- A failing connection says why; a working one carries no old reason.
                ADD CONSTRAINT connections_last_error CHECK ((state = 'FAILING') = (last_error IS NOT NULL))
            SQL);

        Schema::create('sync.reads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->string('kind', 16);
            $table->string('requested_by_type', 16)->nullable();
            $table->ulid('requested_by_id')->nullable();
            $table->string('state', 16);
            $table->integer('prices_changed')->default(0);
            $table->integer('stock_changed')->default(0);
            $table->integer('skipped')->default(0);
            $table->string('error', 1000)->nullable();
            $table->timestampTz('queued_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->foreign('store_id', 'reads_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->index(['store_id', 'queued_at'], 'reads_store_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sync.reads
                ADD CONSTRAINT reads_kind CHECK (kind IN ('NOTIFIED', 'HOURLY', 'NIGHTLY', 'NOW', 'CODE')),
                ADD CONSTRAINT reads_state CHECK (state IN ('QUEUED', 'RUNNING', 'DONE', 'FAILED')),
                ADD CONSTRAINT reads_counts CHECK (prices_changed >= 0 AND stock_changed >= 0 AND skipped >= 0),
                -- Who asked: staff only - Sync now, Retry, wiring (§1.2, §1.3). The provider's notices and
                -- the hourly read are never anyone's; which kind wiring's first read is, step 3 decides.
                ADD CONSTRAINT reads_requested_by CHECK (
                    (requested_by_type IS NULL) = (requested_by_id IS NULL)
                    AND (requested_by_type IS NULL OR (requested_by_type = 'STAFF' AND kind NOT IN ('NOTIFIED', 'HOURLY')))
                ),
                -- Started once out of the queue, finished once done or failed. A failed read may never
                -- have started: a job that gave up waiting, or one dropped when its store was unwired.
                ADD CONSTRAINT reads_times CHECK (
                    (state = 'QUEUED' AND started_at IS NULL) OR (state IN ('RUNNING', 'DONE') AND started_at IS NOT NULL) OR state = 'FAILED'
                ),
                ADD CONSTRAINT reads_finished CHECK ((state IN ('DONE', 'FAILED')) = (finished_at IS NOT NULL)),
                ADD CONSTRAINT reads_error CHECK ((state = 'FAILED') = (error IS NOT NULL))
            SQL);

        Schema::create('sync.report_entries', function (Blueprint $table) {
            $table->id();
            $table->ulid('store_id');
            $table->string('kind', 24);
            // The provider's code, which may be anything: a longer one is cut before it is written.
            $table->string('code', 255)->nullable();
            $table->ulid('variant_id')->nullable();
            $table->ulid('sale_id')->nullable();
            $table->jsonb('detail');
            $table->timestampTz('opened_at');
            $table->timestampTz('seen_at');
            $table->timestampTz('closed_at')->nullable();
            $table->string('closed_by', 64)->nullable();

            $table->foreign('store_id', 'report_entries_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->index(['store_id', 'closed_at'], 'report_entries_store_idx');
        });

        // A size or a sale keeps its entry when it goes (no key on them), as the audit log names its
        // subjects. An entry is about one thing: a code we do not have, a size of ours (its code read
        // from Catalog, so a corrected code is still the same entry), or the whole store. One open
        // entry per store, kind, code and size: the same thing again updates it.
        DB::statement(<<<'SQL'
            ALTER TABLE sync.report_entries
                ADD CONSTRAINT report_entries_kind CHECK (kind IN ('UNKNOWN_CODE', 'NOT_IN_PROVIDER', 'ARCHIVED_SKIPPED', 'OFF_OR_DRAFT_PRICED', 'SALE_STOPPED', 'BAD_VALUE', 'READ_FAILED')),
                ADD CONSTRAINT report_entries_detail CHECK (jsonb_typeof(detail) = 'object'),
                ADD CONSTRAINT report_entries_subject CHECK (
                    (kind = 'UNKNOWN_CODE' AND code IS NOT NULL AND variant_id IS NULL)
                    OR (kind = 'READ_FAILED' AND code IS NULL AND variant_id IS NULL)
                    OR (kind NOT IN ('UNKNOWN_CODE', 'READ_FAILED') AND code IS NULL AND variant_id IS NOT NULL)
                ),
                -- A stopped sale names the sale; nothing else does.
                ADD CONSTRAINT report_entries_sale CHECK ((kind = 'SALE_STOPPED') = (sale_id IS NOT NULL)),
                ADD CONSTRAINT report_entries_closed CHECK ((closed_at IS NULL) = (closed_by IS NULL))
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX report_entries_open ON sync.report_entries (store_id, kind, COALESCE(code, ''), COALESCE(variant_id, ''))
                WHERE closed_at IS NULL
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sync.report_entries');
        Schema::dropIfExists('sync.reads');
        Schema::dropIfExists('sync.connections');
    }
};
