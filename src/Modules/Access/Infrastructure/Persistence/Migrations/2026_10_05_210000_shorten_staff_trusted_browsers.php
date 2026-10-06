<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| A trusted browser skips the staff sign-in code for 12 hours, no longer 30 days (owner,
| 2026-10-05; access.md amendment 61). A browser trusted before this keeps no more than the new
| 12 hours from when it was trusted - one trusted a day ago asks for a code at its next sign-in.
| Nothing to undo: the old expiry is not kept.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE access.staff_trusted_browsers
            SET expires_at = LEAST(expires_at, created_at + interval '12 hours')
            SQL);
    }

    public function down(): void {}
};
