<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| The store's bank account moves from B2B to Payments (payments.md §1.9, b2b.md amendment 33): what a store
| has saved stays as it is, with who saved it and when, under the new names. A stored row wins over nothing:
| Payments has no such rows before this runs, but a re-run after a failed deploy keeps what is there.
*/

return new class extends Migration
{
    public function up(): void
    {
        $this->move('b2b.bank.', 'payments.bank.');
    }

    public function down(): void
    {
        $this->move('payments.bank.', 'b2b.bank.');
    }

    private function move(string $from, string $to): void
    {
        $names = ['iban', 'name', 'holder'];

        foreach ($names as $name) {
            DB::statement(
                'INSERT INTO platform.settings (store_id, key, value, updated_by, updated_at)
                 SELECT store_id, ?, value, updated_by, updated_at FROM platform.settings WHERE key = ?
                 ON CONFLICT (store_id, key) DO NOTHING',
                [$to.$name, $from.$name],
            );
            DB::table('platform.settings')->where('key', $from.$name)->delete();
        }
    }
};
