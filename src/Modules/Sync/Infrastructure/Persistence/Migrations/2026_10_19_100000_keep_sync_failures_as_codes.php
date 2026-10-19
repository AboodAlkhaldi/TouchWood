<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Stage 5's screens step 6 (sync.md §4.1.1, Q31): Sync's own reasons a read failed are kept as a code,
| written in the reader's language on the screens - no longer as an English sentence. The sentences
| already written become their codes: a read's error, a failing store's last error, and the reason a
| failed read's entry and its record carry. A provider's own reason is left as it came.
|
| Undone, the codes become the sentences again.
*/

return new class extends Migration
{
    /** @var array<string, list<string>> each code, and the sentences it stood for */
    private const array WRITTEN = [
        'STOPPED_REWIRED' => ['Stopped: the store was unwired, or wired again, while it ran.'],
        'WORKER_GONE' => ['Stopped: its worker went away.'],
        'UNWIRED' => ['The store was unwired.'],
        'SUPERSEDED' => ['Superseded: a read of the same kind waits.'],
        'JOB_FAILED' => ['The read failed: see the failed jobs screen.'],
    ];

    public function up(): void
    {
        foreach (self::WRITTEN as $code => $sentences) {
            foreach ($sentences as $sentence) {
                $this->rewrite($sentence, $code);
            }
        }
    }

    public function down(): void
    {
        foreach (self::WRITTEN as $code => $sentences) {
            $this->rewrite($code, $sentences[0]);
        }
    }

    private function rewrite(string $from, string $to): void
    {
        DB::table('sync.reads')->where('error', $from)->update(['error' => $to]);
        DB::table('sync.connections')->where('last_error', $from)->update(['last_error' => $to]);

        foreach (['sync.report_entries', 'sync.read_reports'] as $table) {
            DB::update(
                "UPDATE {$table} SET detail = jsonb_set(detail, '{error}', to_jsonb(?::text)) WHERE kind = 'READ_FAILED' AND detail->>'error' = ?",
                [$to, $from],
            );
        }
    }
};
