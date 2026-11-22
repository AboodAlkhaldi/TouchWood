<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| ops.md §1.11, §5: the daily report sent by email at the time the person chooses - off by default (no
| row), one time per person per store, or one for all stores (no store; owner, 2026-10-28, §9.3 #15).
| The time is read in the store's time zone; all stores' in the base store's. `version` grows with every
| change, so the email already queued for the old time knows to stop; `next_due_at` is the one moment the
| email may go next - a job runs only if its moment is that one, and moves it on in the same statement,
| so a double submit, a race or a job tried again never starts a second email.
|
| Each CHECK is the last line: DailyReportEmail refuses the value first.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops.daily_report_settings', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('staff_id');
            $table->ulid('store_id')->nullable();
            $table->boolean('is_on');
            $table->char('send_at', 5);
            $table->integer('version');
            $table->timestampTz('next_due_at')->nullable();
            $table->timestampTz('updated_at');

            $table->primary('id', 'daily_report_settings_pkey');
            $table->foreign('staff_id', 'daily_report_settings_staff')->references('id')->on('access.staff_users')->restrictOnDelete();
            $table->foreign('store_id', 'daily_report_settings_store')->references('id')->on('platform.stores')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ops.daily_report_settings
                ADD CONSTRAINT daily_report_settings_send_at CHECK (send_at ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$'),
                ADD CONSTRAINT daily_report_settings_version CHECK (version >= 1),
                ADD CONSTRAINT daily_report_settings_next_due CHECK ((next_due_at IS NOT NULL) = is_on)
            SQL);

        // One per person per store, and one for all stores.
        DB::statement('CREATE UNIQUE INDEX daily_report_settings_one ON ops.daily_report_settings (staff_id, store_id) NULLS NOT DISTINCT');
    }

    public function down(): void
    {
        Schema::dropIfExists('ops.daily_report_settings');
    }
};
