<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Latin digits everywhere (owner, 2026-10-06; b2b.md amendment 29): a company's CR number, tax number
| and address saved before the rule are turned once, in companies and in the applications that sent
| them, as RegistrationNumber and CompanyAddress now turn what is typed. Only rows holding an
| Arabic-Indic or Extended Arabic-Indic digit are touched; running it again changes nothing. A
| company's name is kept as typed. The audit log records only that these fields changed, never their
| values, so it holds nothing to turn.
|
| Nothing to undo: the digits typed are not kept.
*/

return new class extends Migration
{
    private const string FROM = '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹';

    private const string TO = '01234567890123456789';

    private const array COLUMNS = ['cr_number', 'tax_number', 'address'];

    public function up(): void
    {
        foreach (['b2b.companies', 'b2b.applications'] as $table) {
            foreach (self::COLUMNS as $column) {
                DB::update(
                    "UPDATE {$table} SET {$column} = translate({$column}, ?, ?) WHERE {$column} ~ '[٠-٩۰-۹]'",
                    [self::FROM, self::TO],
                );
            }
        }
    }

    public function down(): void {}
};
