<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ViewStaff;

use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Query\ListStaff\StaffSummary;
use Modules\Access\Application\Query\StaffReader;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Enums\StaffStatus;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * One staff member (spec §3.3, amendments 9 and 43). Anyone the reader may not see — a person in a
 * store they do not cover, or a Super Admin when the reader is not one — is answered exactly as an
 * id that never existed: "no such staff member".
 */
final readonly class ViewStaffHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_VIEW;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffReader $staff,
    ) {}

    /**
     * @throws StaffNotFound
     */
    public function handle(ViewStaff $query): StaffSummary
    {
        $stores = $this->authorizer->storesWith(self::PERMISSION);
        $row = $stores === [] ? null : $this->staff->member($query->staffId);

        if ($row === null) {
            throw new StaffNotFound($query->staffId);
        }

        $unlimited = $this->rules->author()->isUnlimited();

        if ($row['is_super_admin'] === true && ! $unlimited) {
            throw new StaffNotFound($query->staffId);
        }

        $mine = $stores === null ? null : array_map(static fn (StoreId $store): string => $store->value, $stores);

        if (! $this->covers($mine, $row)) {
            throw new StaffNotFound($query->staffId);
        }

        $isAdmin = $row['role_level'] === RoleLevel::Admin->value || $row['is_super_admin'] === true;
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
     * @param  list<string>|null  $mine
     * @param  array<string, mixed>  $row
     */
    private function covers(?array $mine, array $row): bool
    {
        if ($mine === null) {
            return true;
        }

        if ($row['access_level'] === 'ALL_STORES' || $row['access_level'] === null) {
            return false;
        }

        /** @var list<string> $theirs */
        $theirs = $row['stores'];

        return $theirs !== [] && array_diff($theirs, $mine) === [];
    }
}
