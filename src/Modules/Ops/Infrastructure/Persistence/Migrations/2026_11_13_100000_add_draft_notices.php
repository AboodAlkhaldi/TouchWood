<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| ops.md §9.7 #1 (content.md §2.3 row O1): a notice may be a **draft** - saved with no time, never sent by the
| scheduler, offered to a campaign and started when the campaign goes live. `send_at` is null for a draft, and for a
| draft cancelled; every other notice keeps its time (`status` is NOT NULL, so each branch is true or false, never
| null). A draft has not started (notices_started counts it with the waiting ones).
|
| Down: drafts and cancelled drafts are deleted first, since the old CHECKs cannot hold them.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ops.notices ALTER COLUMN send_at DROP NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE ops.notices
                DROP CONSTRAINT notices_status,
                DROP CONSTRAINT notices_started,
                ADD CONSTRAINT notices_status CHECK (status IN ('DRAFT', 'SCHEDULED', 'SENDING', 'SENT', 'CANCELLED', 'FAILED')),
                ADD CONSTRAINT notices_started CHECK ((started_at IS NULL) = (status IN ('DRAFT', 'SCHEDULED', 'CANCELLED'))),
                ADD CONSTRAINT notices_draft_time CHECK ((status = 'DRAFT' AND send_at IS NULL) OR (status <> 'DRAFT' AND (send_at IS NOT NULL OR status = 'CANCELLED')))
            SQL);
        // What a campaign's page lists: the store's drafts, newest first.
        DB::statement("CREATE INDEX notices_drafts ON ops.notices (store_id, created_at DESC, id DESC) WHERE status = 'DRAFT'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ops.notices_drafts');
        DB::statement("DELETE FROM ops.notices WHERE status = 'DRAFT' OR send_at IS NULL");
        DB::statement(<<<'SQL'
            ALTER TABLE ops.notices
                DROP CONSTRAINT notices_draft_time,
                DROP CONSTRAINT notices_status,
                DROP CONSTRAINT notices_started,
                ADD CONSTRAINT notices_status CHECK (status IN ('SCHEDULED', 'SENDING', 'SENT', 'CANCELLED', 'FAILED')),
                ADD CONSTRAINT notices_started CHECK ((started_at IS NULL) = (status IN ('SCHEDULED', 'CANCELLED')))
            SQL);
        DB::statement('ALTER TABLE ops.notices ALTER COLUMN send_at SET NOT NULL');
    }
};
