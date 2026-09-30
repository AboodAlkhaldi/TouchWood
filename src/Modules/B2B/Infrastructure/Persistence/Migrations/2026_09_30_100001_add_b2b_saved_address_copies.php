<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| b2b.md §5, §5.1, amendment 16(f): the address is picked from the account's saved addresses and
| kept as a copy — its text as the store's format writes it, and which saved address it was.
|
| The text column is widened from varchar(500) to text, at most 6,000 characters: what Access's own
| limits let a formatted address reach. The addresses already written keep their text and have no
| saved address behind them. Deleting a saved address leaves every copy as it is and only forgets
| where it came from (ON DELETE SET NULL).
|
| CompanyAddress refuses a longer copy first; the CHECKs are the backstop.
*/

return new class extends Migration
{
    private const array TABLES = ['companies', 'applications'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE b2b.{$table} ALTER COLUMN address TYPE text");
            DB::statement("ALTER TABLE b2b.{$table} ADD CONSTRAINT {$table}_address_length CHECK (address IS NULL OR char_length(address) <= 6000)");

            Schema::table("b2b.{$table}", function (Blueprint $blueprint): void {
                $blueprint->ulid('address_id')->nullable();

                $blueprint->foreign('address_id')->references('id')->on('access.addresses')->nullOnDelete();
                $blueprint->index('address_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table("b2b.{$table}", function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['address_id']);
                $blueprint->dropIndex(['address_id']);
                $blueprint->dropColumn('address_id');
            });

            DB::statement("ALTER TABLE b2b.{$table} DROP CONSTRAINT IF EXISTS {$table}_address_length");
            // A copy longer than the old column could hold cannot go back into it.
            DB::statement("ALTER TABLE b2b.{$table} ALTER COLUMN address TYPE varchar(500)");
        }
    }
};
