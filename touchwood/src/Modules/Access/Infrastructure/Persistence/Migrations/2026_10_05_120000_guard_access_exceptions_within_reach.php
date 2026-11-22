<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| access.md amendment 59 (owner, 2026-10-04): **Where It Reaches bounds every action.** An action
| given stores of its own lies inside the staff member's store row from now on; `RoleAssignment`
| refuses anything else, and a staff member's stores are the row alone.
|
| Before it, an exception could reach beyond the row. Cutting such an exception back, or widening
| the row to hold it, would each change what somebody may do without anyone deciding it - so this
| changes nobody's access, and stops instead, naming every staff member and action to be put right
| by hand. When it was written no database held a single exception (2026-10-05), so it passes.
|
| What it does remove is an action's "every store" under an every-store row: it equals the row,
| which the domain no longer keeps, and the staff page would show it as custom stores with none
| listed (the review of P4). Removing it changes nobody's access.
*/

return new class extends Migration
{
    public function up(): void
    {
        /** @var list<object{staff_user_id: string, permission: string}> $beyond */
        $beyond = DB::select(<<<'SQL'
            SELECT e.staff_user_id, e.permission
            FROM access.role_assignment_exceptions e
            JOIN access.role_assignments a ON a.staff_user_id = e.staff_user_id
            WHERE a.access_level = 'SELECTED_STORES'
              AND (
                  e.access_level = 'ALL_STORES'
                  OR EXISTS (
                      SELECT 1 FROM access.role_assignment_exception_stores es
                      WHERE es.staff_user_id = e.staff_user_id
                        AND es.permission = e.permission
                        AND NOT EXISTS (
                            SELECT 1 FROM access.role_assignment_stores s
                            WHERE s.staff_user_id = e.staff_user_id AND s.store_id = es.store_id
                        )
                  )
              )
            ORDER BY e.staff_user_id, e.permission
            SQL);

        if ($beyond !== []) {
            $named = array_map(static fn (object $row): string => "{$row->staff_user_id} {$row->permission}", $beyond);

            throw new RuntimeException("These actions reach stores outside their staff member's store row (access.md amendment 59). Put each inside the row, or remove it, then migrate again:\n".implode("\n", $named));
        }

        DB::statement(<<<'SQL'
            DELETE FROM access.role_assignment_exceptions e
            USING access.role_assignments a
            WHERE a.staff_user_id = e.staff_user_id
              AND a.access_level = 'ALL_STORES'
              AND e.access_level = 'ALL_STORES'
            SQL);
    }

    public function down(): void
    {
        // Nothing to put back: what was removed equalled the row, and reached what the row reaches.
    }
};
