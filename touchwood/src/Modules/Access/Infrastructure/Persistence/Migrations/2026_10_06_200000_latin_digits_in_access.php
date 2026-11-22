<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Latin digits everywhere (owner, 2026-10-06; access.md amendment 63): what was saved before the rule
| is turned once, as the value objects now turn what is typed - an address's fields and the staff
| address. Phone numbers were already Latin (E.164). Only rows holding an Arabic-Indic or Extended
| Arabic-Indic digit are touched; nothing else in them changes, so running it again changes nothing.
|
| An address's fields are JSON whose keys are the format's ASCII keys, so turning the whole text
| turns only the values. The audit log records only that these fields changed, never their values,
| so it holds nothing to turn.
|
| Nothing to undo: the digits typed are not kept.
*/

return new class extends Migration
{
    private const string FROM = '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹';

    private const string TO = '01234567890123456789';

    public function up(): void
    {
        DB::update(
            "UPDATE access.addresses SET fields = translate(fields::text, ?, ?)::jsonb WHERE fields::text ~ '[٠-٩۰-۹]'",
            [self::FROM, self::TO],
        );
        DB::update(
            "UPDATE access.staff_users SET address = translate(address, ?, ?) WHERE address ~ '[٠-٩۰-۹]'",
            [self::FROM, self::TO],
        );
    }

    public function down(): void {}
};
