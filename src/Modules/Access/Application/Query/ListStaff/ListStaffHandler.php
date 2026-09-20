<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ListStaff;

use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Query\StaffReader;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Enums\StaffStatus;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Who works here, as this reader may see them (spec §3.3, amendments 9 and 43):
 *
 * - a staff member is visible only when the reader holds "see staff" in **all** of their stores;
 * - an **admin** shows a name, a role and a status, and nothing else — no job title, email or phone;
 * - a **Super Admin** is not here at all, and is in no count, unless the reader is one themselves.
 */
final readonly class ListStaffHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_VIEW;

    private const int PER_PAGE_MAX = 100;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffReader $staff,
    ) {}

    /**
     * @throws Unauthorized when they hold the permission in no store at all
     */
    public function handle(ListStaff $query): StaffPage
    {
        $stores = $this->authorizer->storesWith(self::PERMISSION);

        if ($stores === []) {
            throw new Unauthorized(self::PERMISSION);
        }

        $perPage = min(max($query->perPage, 1), self::PER_PAGE_MAX);
        $page = max($query->page, 1);
        $status = $query->status === null ? null : StaffStatus::from($query->status)->value;
        $unlimited = $this->rules->author()->isUnlimited();
        $mine = $stores === null ? null : array_map(static fn (StoreId $store): string => $store->value, $stores);

        $found = $unlimited
            ? $this->both($query->search, $status, $page, $perPage)
            : $this->staff->staff($query->search, $status, $page, $perPage);

        $rows = [];

        foreach ($found['rows'] as $row) {
            if ($this->covers($mine, $row)) {
                $rows[] = $this->toSummary($row, $unlimited);
            }
        }

        return new StaffPage($rows, $found['total'], $page, $perPage);
    }

    /**
     * A Super Admin sees everyone, Super Admins included.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    private function both(?string $search, ?string $status, int $page, int $perPage): array
    {
        $staff = $this->staff->staff($search, $status, $page, $perPage);
        $supers = $this->staff->superAdmins($search, $status, $page, $perPage);

        return [
            'total' => $staff['total'] + $supers['total'],
            'rows' => [...$supers['rows'], ...$staff['rows']],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function storesOf(array $row): array
    {
        $stores = $row['stores'];

        return is_array($stores) ? array_values(array_filter($stores, is_string(...))) : [];
    }

    /**
     * @param  list<string>|null  $mine  the reader's stores; null: every store
     * @param  array<string, mixed>  $row
     */
    private function covers(?array $mine, array $row): bool
    {
        if ($mine === null) {
            return true;
        }

        // Every store, against a reader who has some of them: not theirs to see (amendment 9).
        if ($row['access_level'] === 'ALL_STORES' || $row['access_level'] === null) {
            return false;
        }

        /** @var list<string> $theirs */
        $theirs = $row['stores'];

        return $theirs !== [] && array_diff($theirs, $mine) === [];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toSummary(array $row, bool $unlimited): StaffSummary
    {
        $isAdmin = $row['role_level'] === RoleLevel::Admin->value || $row['is_super_admin'] === true;
        // An admin's contact details are for a Super Admin only (amendment 43).
        $open = $unlimited || ! $isAdmin;

        return new StaffSummary(
            (string) $row['id'],
            (string) $row['first_name'],
            (string) $row['last_name'],
            $open ? (string) $row['job_title'] : null,
            $open ? (string) $row['email'] : null,
            $open ? ($row['phone'] === null ? null : (string) $row['phone']) : null,
            StaffStatus::from((string) $row['status']),
            $row['role_id'] === null ? null : (string) $row['role_id'],
            (string) $row['role_name_ar'],
            (string) $row['role_name_en'],
            $isAdmin,
            $row['access_level'] === 'ALL_STORES',
            $row['access_level'] === 'ALL_STORES' ? [] : $this->storesOf($row),
            (string) $row['joined_at'],
        );
    }
}
