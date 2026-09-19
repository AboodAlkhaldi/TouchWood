<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Public\Enums\StaffStatus;
use stdClass;

final readonly class DatabaseStaffUserRepository implements StaffUserRepository
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function byId(string $id): ?StaffUser
    {
        if (! Ulids::valid($id)) {
            return null;
        }

        $row = $this->db->table('access.staff_users')->where('id', strtolower($id))->lockForUpdate()->first();

        return $row instanceof stdClass ? StaffUser::reconstitute(
            (string) $row->id,
            (string) $row->email,
            (string) $row->first_name,
            (string) $row->last_name,
            (string) $row->locale,
            StaffStatus::from((string) $row->status),
            (bool) $row->is_super_admin,
        ) : null;
    }

    public function names(array $ids): array
    {
        $found = [];

        foreach ($this->db->table('access.staff_users')->whereIn('id', $ids)->get(['id', 'first_name', 'last_name']) as $row) {
            $found[(string) $row->id] = trim($row->first_name.' '.$row->last_name);
        }

        $names = [];

        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $names[$id] = $found[$id];
            }
        }

        return $names;
    }
}
