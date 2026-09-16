<?php

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
            $table->id();
            $table->timestampTz('occurred_at');
            $table->char('store_id', 26)->nullable();
            $table->string('actor_type', 16);
            $table->char('actor_id', 26)->nullable();
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
                ADD CONSTRAINT audit_entries_actor_type CHECK (actor_type IN ('STAFF', 'CUSTOMER', 'SYSTEM')),
                ADD CONSTRAINT audit_entries_actor_id CHECK ((actor_type = 'SYSTEM') = (actor_id IS NULL)),
                ADD CONSTRAINT audit_entries_ip_staff_only CHECK (ip_address IS NULL OR actor_type = 'STAFF')
            SQL);

        DB::statement('CREATE INDEX audit_entries_subject_idx ON platform.audit_entries (subject_type, subject_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_entries_store_idx ON platform.audit_entries (store_id, occurred_at DESC)');
        DB::statement('CREATE INDEX audit_entries_actor_idx ON platform.audit_entries (actor_type, actor_id, occurred_at DESC)');
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
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform.audit_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS platform.audit_entries_are_append_only()');
    }
};
