<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| ops.md §1.11, §5: every daily report made is kept - its PDF in the app's private files, its row here -
| and listed, to open again exactly as it was. One store's, or every store's (no store). Kept twelve
| months, file and row together. `emailed_at` is when the scheduled email took it.
|
| Each CHECK is the last line: DailyReportFile refuses the value first.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops.daily_reports', function (Blueprint $table) {
            $table->ulid('id');
            $table->ulid('store_id')->nullable();
            $table->date('day');
            $table->boolean('past_day');
            $table->ulid('made_by');
            $table->timestampTz('made_at');
            $table->char('locale', 2);
            $table->string('file', 200);
            $table->integer('bytes');
            $table->timestampTz('emailed_at')->nullable();

            $table->primary('id', 'daily_reports_pkey');
            $table->foreign('store_id', 'daily_reports_store')->references('id')->on('platform.stores')->restrictOnDelete();
            $table->foreign('made_by', 'daily_reports_made_by')->references('id')->on('access.staff_users')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ops.daily_reports
                ADD CONSTRAINT daily_reports_locale CHECK (locale IN ('ar', 'en')),
                ADD CONSTRAINT daily_reports_file CHECK (file ~ '^ops/daily-reports/[0-9]{4}/[0-9]{2}/[0-9a-z]{26}\.pdf$'),
                ADD CONSTRAINT daily_reports_bytes CHECK (bytes > 0),
                ADD CONSTRAINT daily_reports_emailed CHECK (emailed_at IS NULL OR emailed_at >= made_at)
            SQL);

        // The list, newest first; and the nightly removal of what is twelve months old.
        DB::statement('CREATE INDEX daily_reports_by_made ON ops.daily_reports (made_at DESC, id DESC)');
        DB::statement('CREATE UNIQUE INDEX daily_reports_file_unique ON ops.daily_reports (file)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ops.daily_reports');
    }
};
