<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| access.md amendment 57 (from the security review of amendment 54, 2026-10-02): **a former Super
| Admin stays hidden.** Revoking the power cleared `is_super_admin`, and everything that hid a Super
| Admin read that flag — so a revoke unmasked the person's whole history to admins and staff.
|
| `was_super_admin` is set when a Super Admin is made or promoted and never cleared. It is filled here
| from the flag, and from the audit log for anyone already revoked: every entry about a staff member
| that recorded `is_super_admin` as true, before or after a change (the grant, the revoke, an
| invitation as Super Admin).
|
| CHECK `staff_users_super_admin_marked`: a Super Admin is someone who has been one. The domain holds
| the same rule first (StaffUser's constructor), so the database never receives a row it would refuse.
*/

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE access.staff_users ADD COLUMN was_super_admin boolean NOT NULL DEFAULT false');
        DB::statement('UPDATE access.staff_users SET was_super_admin = true WHERE is_super_admin');
        DB::statement(<<<'SQL'
            UPDATE access.staff_users s SET was_super_admin = true
            WHERE EXISTS (
                SELECT 1 FROM platform.audit_entries e
                WHERE e.subject_type = 'access.staff_user'
                  AND e.subject_id = s.id
                  AND (e.changes->'is_super_admin'->>0 = 'true' OR e.changes->'is_super_admin'->>1 = 'true')
            )
            SQL);
        DB::statement('ALTER TABLE access.staff_users ADD CONSTRAINT staff_users_super_admin_marked CHECK (NOT is_super_admin OR was_super_admin)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE access.staff_users DROP CONSTRAINT IF EXISTS staff_users_super_admin_marked');
        DB::statement('ALTER TABLE access.staff_users DROP COLUMN IF EXISTS was_super_admin');
    }
};
