<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| ops.md §1.8 (owner, 2026-11-01): a staff message may go to the members of one of the store's Promotions
| segments, as a notice may (§1.7): `segment_id` is Promotions' own id, no foreign key across modules, read
| through PromotionsApi::segmentMembers, store-scoped, when it sends. Its batches page the members:
| `cursor_type` SEGMENT, `cursor_after` the last member reached. Each CHECK is the last line: the handler
| refuses the value first.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ops.staff_messages ADD COLUMN segment_id char(26) NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE ops.staff_messages
                DROP CONSTRAINT staff_messages_audience,
                DROP CONSTRAINT staff_messages_cursor_type,
                ADD CONSTRAINT staff_messages_audience CHECK (audience IN ('ONE', 'STORE', 'SEGMENT')),
                ADD CONSTRAINT staff_messages_segment CHECK ((segment_id IS NOT NULL) = (audience = 'SEGMENT')),
                ADD CONSTRAINT staff_messages_cursor_type CHECK (cursor_type IS NULL OR cursor_type IN ('INDIVIDUAL', 'COMPANY', 'SEGMENT')),
                ADD CONSTRAINT staff_messages_segment_cursor CHECK (cursor_type IS DISTINCT FROM 'SEGMENT' OR segment_id IS NOT NULL)
            SQL);
    }

    public function down(): void
    {
        DB::statement("DELETE FROM ops.staff_messages WHERE audience = 'SEGMENT'");
        DB::statement(<<<'SQL'
            ALTER TABLE ops.staff_messages
                DROP CONSTRAINT staff_messages_segment_cursor,
                DROP CONSTRAINT staff_messages_cursor_type,
                DROP CONSTRAINT staff_messages_segment,
                DROP CONSTRAINT staff_messages_audience,
                ADD CONSTRAINT staff_messages_audience CHECK (audience IN ('ONE', 'STORE')),
                ADD CONSTRAINT staff_messages_cursor_type CHECK (cursor_type IS NULL OR cursor_type IN ('INDIVIDUAL', 'COMPANY'))
            SQL);
        DB::statement('ALTER TABLE ops.staff_messages DROP COLUMN segment_id');
    }
};
