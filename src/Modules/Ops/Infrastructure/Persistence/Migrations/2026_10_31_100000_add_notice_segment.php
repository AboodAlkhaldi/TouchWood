<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| ops.md §1.7: an offer notice's audience is every customer of the store, or the members of one of the
| store's Promotions segments (`segment_id`, Promotions' own id - no foreign key across modules; the
| segment is read through PromotionsApi::segmentMembers, store-scoped, when the notice sends). A segment
| notice's batches page the segment's members: `cursor_type` SEGMENT, `cursor_after` the last member
| reached. Each CHECK is the last line: the handlers refuse the value first.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ops.notices ADD COLUMN segment_id char(26) NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE ops.notices
                DROP CONSTRAINT notices_cursor_type,
                ADD CONSTRAINT notices_cursor_type CHECK (cursor_type IS NULL OR cursor_type IN ('INDIVIDUAL', 'COMPANY', 'SEGMENT')),
                ADD CONSTRAINT notices_segment_cursor CHECK (cursor_type IS DISTINCT FROM 'SEGMENT' OR segment_id IS NOT NULL)
            SQL);
    }

    public function down(): void
    {
        DB::statement("UPDATE ops.notices SET cursor_type = NULL WHERE cursor_type = 'SEGMENT'");
        DB::statement(<<<'SQL'
            ALTER TABLE ops.notices
                DROP CONSTRAINT notices_segment_cursor,
                DROP CONSTRAINT notices_cursor_type,
                ADD CONSTRAINT notices_cursor_type CHECK (cursor_type IS NULL OR cursor_type IN ('INDIVIDUAL', 'COMPANY'))
            SQL);
        DB::statement('ALTER TABLE ops.notices DROP COLUMN segment_id');
    }
};
