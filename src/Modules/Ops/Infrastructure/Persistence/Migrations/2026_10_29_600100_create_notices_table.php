<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| ops.md §1.7, §5: a store's offer notices. Written in both languages - a title and a line, the bell's
| own two - with an optional page of the shop; to every customer of the store; by the bell, the email,
| or both. Sent now, or scheduled: a scheduled notice may be changed or cancelled until it starts
| sending, and once sending starts it cannot be recalled.
|
| Sending goes in batches (§1.7): `cursor_type` and `cursor_after` are where the next batch starts, so a
| batch run twice never sends twice; the counts are what the notice shows as sent. FAILED: a batch
| failed its last attempt; retrying it from the failed jobs screen carries on. A sent or cancelled
| notice is deleted twelve months after it was made (§5). Each CHECK is the last line: the handlers
| refuse the value first (NoticeText, the commands).
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops.notices', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->text('title_ar');
            $table->text('title_en');
            $table->text('body_ar');
            $table->text('body_en');
            $table->text('link')->nullable();
            $table->boolean('by_bell');
            $table->boolean('by_email');
            $table->string('status', 16);
            $table->timestampTz('send_at');
            $table->string('cursor_type', 16)->nullable();
            $table->ulid('cursor_after')->nullable();
            $table->integer('bell_count')->default(0);
            $table->integer('email_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->ulid('created_by');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->primary('id', 'notices_pkey');
            $table->foreign('store_id', 'notices_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('created_by', 'notices_created_by')->references('id')->on('access.staff_users')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ops.notices
                ADD CONSTRAINT notices_title CHECK (char_length(title_ar) BETWEEN 1 AND 200 AND char_length(title_en) BETWEEN 1 AND 200),
                ADD CONSTRAINT notices_body CHECK (char_length(body_ar) BETWEEN 1 AND 500 AND char_length(body_en) BETWEEN 1 AND 500),
                ADD CONSTRAINT notices_link CHECK (link IS NULL OR (char_length(link) BETWEEN 1 AND 500 AND link NOT LIKE '/%' AND strpos(link, '//') = 0 AND strpos(link, chr(92)) = 0 AND strpos(link, ':') = 0)),
                ADD CONSTRAINT notices_channels CHECK (by_bell OR by_email),
                ADD CONSTRAINT notices_status CHECK (status IN ('SCHEDULED', 'SENDING', 'SENT', 'CANCELLED', 'FAILED')),
                ADD CONSTRAINT notices_cursor_type CHECK (cursor_type IS NULL OR cursor_type IN ('INDIVIDUAL', 'COMPANY')),
                ADD CONSTRAINT notices_counts CHECK (bell_count >= 0 AND email_count >= 0 AND failed_count >= 0),
                ADD CONSTRAINT notices_started CHECK ((started_at IS NULL) = (status IN ('SCHEDULED', 'CANCELLED'))),
                ADD CONSTRAINT notices_finished CHECK ((finished_at IS NOT NULL) = (status = 'SENT'))
            SQL);

        // The store's list, newest first; the due notices the scheduler starts; the twelve-month removal.
        DB::statement('CREATE INDEX notices_by_store ON ops.notices (store_id, created_at DESC, id DESC)');
        DB::statement("CREATE INDEX notices_due ON ops.notices (send_at) WHERE status = 'SCHEDULED'");
        DB::statement('CREATE INDEX notices_by_age ON ops.notices (created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ops.notices');
    }
};
