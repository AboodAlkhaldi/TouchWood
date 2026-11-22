<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| One currency, one store (platform.md §1.2, §9.7 #4; owner, 2026-10-06): a unique key on the
| store's currency, behind CreateStore's own refusal, so a race between two stores opened with the
| same currency still leaves one.
|
| Stores that already share a currency are named and the migration stops: which store gets which
| currency is the owner's to decide, never this file's.
*/

return new class extends Migration
{
    public function up(): void
    {
        /** @var list<object{currency_code: string, codes: string}> $shared */
        $shared = DB::select(<<<'SQL'
            SELECT currency_code, string_agg(code, ', ' ORDER BY code) AS codes
            FROM platform.stores
            GROUP BY currency_code
            HAVING count(*) > 1
            SQL);

        if ($shared !== []) {
            $lines = array_map(static fn (object $row): string => "{$row->currency_code}: {$row->codes}", $shared);

            throw new RuntimeException('Stores share a currency, and each currency serves one store now (platform.md §9.7 #4). Give each store its own currency first: '.implode('; ', $lines).'.');
        }

        DB::statement('CREATE UNIQUE INDEX stores_one_per_currency ON platform.stores (currency_code)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS platform.stores_one_per_currency');
    }
};
