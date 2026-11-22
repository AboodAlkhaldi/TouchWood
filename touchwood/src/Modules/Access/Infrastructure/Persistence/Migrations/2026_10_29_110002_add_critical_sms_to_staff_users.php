<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Amendment 81: whether the person receives Ops' critical SMS - the payment gateway's key expiring
| in 1 day, or refused (ops.md §1.6). On for everyone, existing staff included; only a Super Admin
| sees the switch, because only they are sent that SMS.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE access.staff_users ADD COLUMN critical_sms boolean NOT NULL DEFAULT true');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE access.staff_users DROP COLUMN IF EXISTS critical_sms');
    }
};
