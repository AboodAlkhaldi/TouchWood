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

        // A reader who is not a Super Admin sees an admin as a name and a role only (amendments 43,
        // 44(e); the status, owner 2026-09-21). A filter on what they cannot see must not answer
        // about it either: ticking "disabled" would otherwise tell them which admins are disabled,
        // and a search would confirm an admin's email or phone by whether the row comes back.
        // coalesce, because a staff member with no role has no level: NULL would make the whole
        // condition NULL, and NOT NULL is NULL too, which drops the row from the page and the
        // total instead of showing it (review of step 7; the same trap as a CHECK that is NULL).
        $hidden = $withSuperAdmins ? 'FALSE' : "(s.is_super_admin OR coalesce(r.level, '') = 'ADMIN')";

        if ($status !== null) {
            $where[] = "(s.status = ? OR {$hidden})";
            $bindings[] = $status;
        }

        if ($search !== null && trim($search) !== '') {
            $like = self::like($search);
            // The name is what every reader sees, so every row is matched on it; the email and the
            // phone match only where the reader would be shown them.
            $where[] = "(lower(s.first_name) LIKE ? OR lower(s.last_name) LIKE ?
                OR ((lower(s.email) LIKE ? OR s.phone LIKE ?) AND NOT {$hidden}))";
            $bindings = [...$bindings, $like, $like, $like, $like];
        }

        $conditions = $where === [] ? 'TRUE' : implode(' AND ', $where);

        $total = $this->db->selectOne(<<<SQL
            SELECT count(*) AS total
            FROM access.staff_users s
            LEFT JOIN access.role_assignments a ON a.staff_user_id = s.id
            LEFT JOIN access.roles r ON r.id = a.role_id
            WHERE {$conditions}
            SQL, $bindings);

        // Newest first for a reader who is shown every joining date; by name for anyone else. An
        // admin's joining date is not theirs to see (amendment 46(d)), and a list ordered by that
        // date would tell them anyway: an admin sitting between two colleagues whose dates they
        // do see is bracketed between them (owner, 2026-09-21).
        $order = $withSuperAdmins
            ? 's.created_at DESC, s.id DESC'
            : 'lower(s.first_name), lower(s.last_name), s.id';

        $rows = $this->db->select(
            $this->select()." WHERE {$conditions} ORDER BY {$order} LIMIT ? OFFSET ?",
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

    public function exceptionsFor(string $staffId): array
    {
        if (! Ulids::valid($staffId)) {
            return [];
        }

        $rows = $this->db->table('access.role_assignment_exceptions as e')
            ->leftJoin('access.role_assignment_exception_stores as s', function ($join): void {
                $join->on('s.staff_user_id', '=', 'e.staff_user_id')->on('s.permission', '=', 'e.permission');
            })
            ->where('e.staff_user_id', strtolower($staffId))
            ->orderBy('e.permission')
            ->get(['e.permission', 's.store_id']);

        $byPermission = [];

        foreach ($rows as $row) {
            $permission = (string) $row->permission;
            $byPermission[$permission] ??= [];

            if ($row->store_id !== null) {
                $byPermission[$permission][] = (string) $row->store_id;
            }
        }

        return $byPermission;
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
                   s.locale, s.is_super_admin, s.created_at, s.avatar_media_id,
                   s.date_of_birth, s.country, s.address,
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
            'avatar_media_id' => $row->avatar_media_id === null ? null : (string) $row->avatar_media_id,
            'date_of_birth' => (string) $row->date_of_birth,
            'country' => (string) $row->country,
            'address' => $row->address === null ? null : (string) $row->address,
        ];
    }
}
