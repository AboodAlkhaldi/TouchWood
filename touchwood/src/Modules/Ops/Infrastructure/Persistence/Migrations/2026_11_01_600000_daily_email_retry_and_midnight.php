<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| ops.md §1.11, the module pass (owner, 2026-11-01).
|
| A report the email made says which setting and moment it was: a job retried from the failed jobs screen
| finds out whether that moment's report exists, and makes it once. The unique index is the last line when
| two run at once. A report made on the button has neither.
|
| 00:00 is refused for a daily email. No live system has run this table yet, so only a developer's row can
| hold it: it moves to 00:01, for the model to read it.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ops.daily_reports ADD COLUMN email_setting_id char(26) NULL, ADD COLUMN email_due_at timestamptz NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE ops.daily_reports
                ADD CONSTRAINT daily_reports_email_setting FOREIGN KEY (email_setting_id) REFERENCES ops.daily_report_settings (id) ON DELETE RESTRICT,
                ADD CONSTRAINT daily_reports_email_moment CHECK ((email_setting_id IS NULL) = (email_due_at IS NULL))
            SQL);
        DB::statement('CREATE UNIQUE INDEX daily_reports_one_per_moment ON ops.daily_reports (email_setting_id, email_due_at) WHERE email_setting_id IS NOT NULL');

        DB::statement("UPDATE ops.daily_report_settings SET send_at = '00:01' WHERE send_at = '00:00'");
        DB::statement("ALTER TABLE ops.daily_report_settings ADD CONSTRAINT daily_report_settings_not_midnight CHECK (send_at <> '00:00')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ops.daily_report_settings DROP CONSTRAINT daily_report_settings_not_midnight');
        DB::statement('DROP INDEX ops.daily_reports_one_per_moment');
        DB::statement('ALTER TABLE ops.daily_reports DROP CONSTRAINT daily_reports_email_moment, DROP CONSTRAINT daily_reports_email_setting, DROP COLUMN email_due_at, DROP COLUMN email_setting_id');
    }
};
