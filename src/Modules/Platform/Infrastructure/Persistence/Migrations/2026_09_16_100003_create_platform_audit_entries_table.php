<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Platform spec §1.5, §5.5. Append-only and kept forever: a trigger refuses every UPDATE and
| DELETE, and there is no retention job.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform.audit_entries', function (Blueprint $table) {
            // bigint identity (spec §5), not bigserial.
            $table->id()->generatedAs()->always();
            // Both dates come from PostgreSQL's clock; only an import may set occurred_at in the past.
            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestampTz('recorded_at')->useCurrent();
            $table->string('source', 16);
            $table->char('store_id', 26)->nullable();
            $table->string('actor_type', 16);
            $table->char('actor_id', 26)->nullable();
            // When the system acts in a queued job: whose action queued it (owner, 2026-09-18).
            $table->string('requested_by_type', 16)->nullable();
            $table->char('requested_by_id', 26)->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 100);
            $table->string('subject_id', 64);
            $table->jsonb('changes')->default('{}');
            $table->string('correlation_id', 64)->nullable();
            $table->ipAddress('ip_address')->nullable();

            $table->foreign('store_id')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE platform.audit_entries
                ADD CONSTRAINT audit_entries_actor_type CHECK (actor_type IN ('STAFF', 'CUSTOMER', 'GUEST', 'INTEGRATION', 'SYSTEM')),
                ADD CONSTRAINT audit_entries_actor_id CHECK ((actor_type = 'SYSTEM') = (actor_id IS NULL)),
                ADD CONSTRAINT audit_entries_requested_by CHECK (
                    (requested_by_type IS NULL) = (requested_by_id IS NULL)
                    AND (requested_by_type IS NULL OR (source = 'JOB' AND actor_type = 'SYSTEM' AND requested_by_type IN ('STAFF', 'CUSTOMER', 'GUEST', 'INTEGRATION')))
                ),
                ADD CONSTRAINT audit_entries_ip_staff_only CHECK (ip_address IS NULL OR actor_type = 'STAFF'),
                ADD CONSTRAINT audit_entries_source CHECK (source IN ('WEB', 'INTEGRATION', 'CONSOLE', 'JOB', 'IMPORT')),
                ADD CONSTRAINT audit_entries_job_acts_as_system CHECK (source <> 'JOB' OR actor_type = 'SYSTEM'),
                ADD CONSTRAINT audit_entries_backdated_import_only CHECK (occurred_at = recorded_at OR (source = 'IMPORT' AND occurred_at < recorded_at))
            SQL);

        DB::statement('CREATE INDEX audit_entries_subject_idx ON platform.audit_entries (subject_type, subject_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_entries_store_idx ON platform.audit_entries (store_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_entries_actor_idx ON platform.audit_entries (actor_type, actor_id, occurred_at DESC)');
        // Everything one person asked the system to do, through queued jobs.
        DB::statement('CREATE INDEX audit_entries_requested_by_idx ON platform.audit_entries (requested_by_type, requested_by_id, occurred_at DESC) WHERE requested_by_id IS NOT NULL');
        DB::statement('CREATE INDEX audit_entries_action_idx ON platform.audit_entries (action, occurred_at DESC)');

        // OR REPLACE: migrate:fresh drops tables but not functions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION platform.audit_entries_are_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'platform.audit_entries is append-only: % is not allowed', TG_OP;
            END;
            $$;

            CREATE TRIGGER audit_entries_append_only
                BEFORE UPDATE OR DELETE ON platform.audit_entries
                FOR EACH ROW EXECUTE FUNCTION platform.audit_entries_are_append_only();

            -- TRUNCATE skips row triggers, so it gets its own.
            CREATE TRIGGER audit_entries_no_truncate
                BEFORE TRUNCATE ON platform.audit_entries
                FOR EACH STATEMENT EXECUTE FUNCTION platform.audit_entries_are_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform.audit_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS platform.audit_entries_are_append_only()');
    }
};
