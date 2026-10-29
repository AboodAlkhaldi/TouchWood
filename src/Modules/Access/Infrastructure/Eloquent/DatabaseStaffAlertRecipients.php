<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Access\Application\Query\StaffAlertRecipients;
use Modules\Access\Public\Dto\StaffAlertRecipientDto;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use stdClass;

/**
 * One statement for every recipient of an alert (amendment 80), the rules of CachedGrantsReader
 * written as SQL: an action reaches the stores of the person's store row, narrowed by its own
 * exception when it has one (amendment 59). For a per-store action the store must lie in both; a
 * store-free one is held once the two share any store. A Super Admin holds everything and is never
 * hidden here - no person reads this list.
 */
final readonly class DatabaseStaffAlertRecipients implements StaffAlertRecipients
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function recipients(string $storeId, StaffNotificationTopic $topic, string $permission, bool $everyStaff, bool $byRole, bool $adminOnly, bool $storeFree): array
    {
        // The rules are the caller's booleans, written in as literals; only the values are bound.
        $flag = static fn (bool $on): string => $on ? 'TRUE' : 'FALSE';
        $store = strtolower($storeId);

        $rows = $this->db->select(<<<SQL
            SELECT s.id, s.locale, s.email, s.phone, s.critical_sms,
                coalesce(np.panel, true) AS panel, coalesce(np.email, false) AS by_email
            FROM access.staff_users s
            LEFT JOIN access.role_assignments a ON a.staff_user_id = s.id
            LEFT JOIN access.roles r ON r.id = a.role_id
            LEFT JOIN access.role_assignment_exceptions e ON e.staff_user_id = s.id AND e.permission = ?
            LEFT JOIN access.staff_notification_preferences np ON np.staff_user_id = s.id AND np.topic = ?
            WHERE s.status = 'ACTIVE'
              AND (
                s.is_super_admin
                OR {$flag($everyStaff)}
                OR (
                    {$flag($byRole)}
                    AND EXISTS (SELECT 1 FROM access.role_permissions p WHERE p.role_id = a.role_id AND p.permission = ?)
                    AND ({$flag(! $adminOnly)} OR r.level = 'ADMIN')
                    AND CASE WHEN {$flag($storeFree)} THEN
                        -- held once the store row and the exception share a store
                        e.staff_user_id IS NULL
                        OR a.access_level = 'ALL_STORES'
                        OR e.access_level = 'ALL_STORES'
                        OR EXISTS (
                            SELECT 1 FROM access.role_assignment_stores st
                            JOIN access.role_assignment_exception_stores es
                                ON es.staff_user_id = st.staff_user_id AND es.store_id = st.store_id AND es.permission = e.permission
                            WHERE st.staff_user_id = s.id
                        )
                    ELSE
                        (a.access_level = 'ALL_STORES'
                            OR EXISTS (SELECT 1 FROM access.role_assignment_stores st WHERE st.staff_user_id = s.id AND st.store_id = ?))
                        AND (e.staff_user_id IS NULL
                            OR e.access_level = 'ALL_STORES'
                            OR EXISTS (SELECT 1 FROM access.role_assignment_exception_stores es
                                WHERE es.staff_user_id = s.id AND es.permission = e.permission AND es.store_id = ?))
                    END
                )
              )
            ORDER BY s.id
            SQL, [$permission, $topic->value, $permission, $store, $store]);

        $recipients = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $recipients[] = new StaffAlertRecipientDto(
                    (string) $row->id,
                    (string) $row->locale,
                    (string) $row->email,
                    $row->phone === null ? null : (string) $row->phone,
                    (bool) $row->panel,
                    (bool) $row->by_email,
                    (bool) $row->critical_sms,
                );
            }
        }

        return $recipients;
    }
}
