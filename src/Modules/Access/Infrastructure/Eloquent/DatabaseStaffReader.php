<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Access\Application\Query\StaffReader;
use stdClass;

/**
 * One statement per page: each staff member with their role and the stores they work in, so a
 * screen never asks again per row.
 *
 * Who the reader may see is part of the statement — both for the rows and for the count. Dropping
 * rows afterwards would return short pages and a total that counted people they may not see, which
 * is a headcount of stores they do not cover (review of step 6).
 */
final readonly class DatabaseStaffReader implements StaffReader
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function staff(?array $readerStoreIds, bool $withSuperAdmins, ?string $search, ?string $status, int $page, int $perPage): array
    {
        $where = [];
        $bindings = [];

        if (! $withSuperAdmins) {
            $where[] = 's.is_super_admin = ?';
            $bindings[] = false;
        }

        if ($readerStoreIds !== null) {
            // Theirs only when every store of theirs is one of the reader's, and they have some:
            // "all stores" or no role at all is nobody's to see but a Super Admin's (amendment 9).
            $where[] = <<<'SQL'
                (a.access_level = 'SELECTED_STORES'
                    AND EXISTS (SELECT 1 FROM access.role_assignment_stores st WHERE st.staff_user_id = s.id)
                    AND NOT EXISTS (
                        SELECT 1 FROM access.role_assignment_stores st
                        WHERE st.staff_user_id = s.id AND st.store_id <> ALL (string_to_array(?, ','))
                    ))
                SQL;
            $bindings[] = implode(',', array_map(strtolower(...), $readerStoreIds));
        }

        if ($status !== null) {
            $where[] = 's.status = ?';
            $bindings[] = $status;
        }

        if ($search !== null && trim($search) !== '') {
            $like = self::like($search);
            $where[] = '(lower(s.email) LIKE ? OR lower(s.first_name) LIKE ? OR lower(s.last_name) LIKE ? OR s.phone LIKE ?)';
            $bindings = [...$bindings, $like, $like, $like, $like];
        }

        $conditions = $where === [] ? 'TRUE' : implode(' AND ', $where);

        $total = $this->db->selectOne(<<<SQL
            SELECT count(*) AS total
            FROM access.staff_users s
            LEFT JOIN access.role_assignments a ON a.staff_user_id = s.id
            WHERE {$conditions}
            SQL, $bindings);

        $rows = $this->db->select(
            $this->select()." WHERE {$conditions} ORDER BY s.created_at DESC, s.id DESC LIMIT ? OFFSET ?",
            [...$bindings, $perPage, (max($page, 1) - 1) * $perPage],
        );

        return [
            'total' => $total instanceof stdClass ? (int) $total->total : 0,
            'rows' => array_values(array_map($this->toRow(...), $rows)),
        ];
    }

    public function member(string $staffId): ?array
    {
        if (! Ulids::valid($staffId)) {
            return null;
        }

        $row = $this->db->selectOne($this->select().' WHERE s.id = ?', [strtolower($staffId)]);

        return $row instanceof stdClass ? $this->toRow($row) : null;
    }

    /**
     * The backslash first: it is PostgreSQL's own escape inside LIKE, so escaping only % and _
     * would let a trailing one swallow the wildcard after it.
     */
    private static function like(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower(trim($search))).'%';
    }

    private function select(): string
    {
        return <<<'SQL'
            SELECT s.id, s.first_name, s.last_name, s.job_title, s.email, s.phone, s.status,
                   s.locale, s.is_super_admin, s.created_at,
                   a.role_id, a.access_level, r.level AS role_level,
                   r.name AS role_name,
                   (SELECT coalesce(json_agg(st.store_id ORDER BY st.store_id), '[]')
                        FROM access.role_assignment_stores st WHERE st.staff_user_id = s.id) AS stores
            FROM access.staff_users s
            LEFT JOIN access.role_assignments a ON a.staff_user_id = s.id
            LEFT JOIN access.roles r ON r.id = a.role_id
            SQL;
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(stdClass $row): array
    {
        $name = $row->role_name === null ? [] : json_decode((string) $row->role_name, true, 512, JSON_THROW_ON_ERROR);
        $stores = json_decode((string) $row->stores, true, 512, JSON_THROW_ON_ERROR);

        return [
            'id' => (string) $row->id,
            'first_name' => (string) $row->first_name,
            'last_name' => (string) $row->last_name,
            'job_title' => (string) $row->job_title,
            'email' => (string) $row->email,
            'phone' => $row->phone === null ? null : (string) $row->phone,
            'status' => (string) $row->status,
            'locale' => (string) $row->locale,
            'is_super_admin' => (bool) $row->is_super_admin,
            'role_id' => $row->role_id === null ? null : (string) $row->role_id,
            'role_level' => $row->role_level === null ? null : (string) $row->role_level,
            'role_name_ar' => is_array($name) && is_string($name['ar'] ?? null) ? $name['ar'] : '',
            'role_name_en' => is_array($name) && is_string($name['en'] ?? null) ? $name['en'] : '',
            'access_level' => $row->access_level === null ? null : (string) $row->access_level,
            'stores' => is_array($stores) ? array_values(array_filter($stores, is_string(...))) : [],
            'joined_at' => (string) $row->created_at,
        ];
    }
}
