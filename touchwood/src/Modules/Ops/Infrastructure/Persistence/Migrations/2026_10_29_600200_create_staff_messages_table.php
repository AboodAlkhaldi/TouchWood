<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| ops.md §1.8, §5: what staff wrote to customers - to one customer, or to every customer of a store -
| who sent it, the channels, the text in both languages, when, and how many it reached. Nothing can be
| recalled: a row is only ever counted on. Sent at once, in batches as a notice is (`cursor_*`).
|
| Deleted twelve months after it was sent (§5), and a message to one customer when that customer's
| account is anonymized (§6). Each CHECK is the last line: the handlers refuse the value first.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops.staff_messages', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id');
            $table->ulid('sender_id');
            $table->string('audience', 16);
            $table->ulid('customer_id')->nullable();
            $table->text('title_ar');
            $table->text('title_en');
            $table->text('body_ar');
            $table->text('body_en');
            $table->boolean('by_bell');
            $table->boolean('by_email');
            $table->string('status', 16);
            $table->string('cursor_type', 16)->nullable();
            $table->ulid('cursor_after')->nullable();
            $table->integer('bell_count')->default(0);
            $table->integer('email_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->timestampTz('created_at');
            $table->timestampTz('finished_at')->nullable();

            $table->primary('id', 'staff_messages_pkey');
            $table->foreign('store_id', 'staff_messages_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('sender_id', 'staff_messages_sender')->references('id')->on('access.staff_users')->restrictOnDelete();
            $table->foreign('customer_id', 'staff_messages_customer')->references('id')->on('access.customers')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ops.staff_messages
                ADD CONSTRAINT staff_messages_audience CHECK (audience IN ('ONE', 'STORE')),
                ADD CONSTRAINT staff_messages_one CHECK ((customer_id IS NOT NULL) = (audience = 'ONE')),
                ADD CONSTRAINT staff_messages_title CHECK (char_length(title_ar) BETWEEN 1 AND 200 AND char_length(title_en) BETWEEN 1 AND 200),
                ADD CONSTRAINT staff_messages_body CHECK (char_length(body_ar) BETWEEN 1 AND 500 AND char_length(body_en) BETWEEN 1 AND 500),
                ADD CONSTRAINT staff_messages_channels CHECK (by_bell OR by_email),
                ADD CONSTRAINT staff_messages_status CHECK (status IN ('SENDING', 'SENT', 'FAILED')),
                ADD CONSTRAINT staff_messages_cursor_type CHECK (cursor_type IS NULL OR cursor_type IN ('INDIVIDUAL', 'COMPANY')),
                ADD CONSTRAINT staff_messages_counts CHECK (bell_count >= 0 AND email_count >= 0 AND failed_count >= 0),
                ADD CONSTRAINT staff_messages_finished CHECK ((finished_at IS NOT NULL) = (status = 'SENT'))
            SQL);

        DB::statement('CREATE INDEX staff_messages_by_store ON ops.staff_messages (store_id, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX staff_messages_by_customer ON ops.staff_messages (customer_id) WHERE customer_id IS NOT NULL');
        DB::statement('CREATE INDEX staff_messages_by_age ON ops.staff_messages (created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ops.staff_messages');
    }
};
