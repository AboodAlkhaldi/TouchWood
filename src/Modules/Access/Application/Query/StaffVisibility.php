<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query;

use Modules\Access\Application\Query\ListStaff\StaffSummary;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Enums\AccessLevel;
use Modules\Access\Public\Enums\StaffStatus;
use Shared\Domain\ValueObject\StoreId;

/**
 * How much of a staff member another one may see (spec §3.3, amendments 9 and 43), in one place so
 * the list and the single view can never drift apart:
 *
 * - a colleague is visible only when the reader holds "see staff" in **all** of their stores;
 * - an **admin** shows a name and a role — no job title, email, phone, stores, joining date or
 *   status (owner, 2026-09-21);
 * - a **Super Admin** is seen only by another Super Admin, and then in full.
 */
final readonly class StaffVisibility
{
    /**
     * @param  list<StoreId>|null  $readerStores  null: every store
     * @param  array<string, mixed>  $row
     */
    public function covers(?array $readerStores, array $row): bool
    {
        if ($readerStores === null) {
            return true;
        }

        // Every store, or no role at all, against a reader who holds some stores: not theirs to
        // see (amendment 9). A staff member with no role is managed by an admin who holds the
        // action somewhere (amendment 27), not seen in these lists.
        if ($row['access_level'] !== AccessLevel::SelectedStores->value) {
            return false;
        }

        $theirs = $this->storesOf($row);
        $mine = array_map(static fn (StoreId $store): string => $store->value, $readerStores);

        return $theirs !== [] && array_diff($theirs, $mine) === [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  bool  $unlimited  whether the reader is a Super Admin or the system
     */
    public function summary(array $row, bool $unlimited): StaffSummary
    {
        $superAdmin = $row['is_super_admin'] === true;
        $isAdmin = $superAdmin || $row['role_level'] === RoleLevel::Admin->value;
        $open = $unlimited || ! $isAdmin;
        // A Super Admin holds every store without a role saying so.
        $allStores = $superAdmin || $row['access_level'] === AccessLevel::AllStores->value;

        return new StaffSummary(
            (string) $row['id'],
            (string) $row['first_name'],
            (string) $row['last_name'],
            $open ? (string) $row['job_title'] : null,
            $open ? (string) $row['email'] : null,
            $open ? ($row['phone'] === null ? null : (string) $row['phone']) : null,
            $open ? StaffStatus::from((string) $row['status']) : null,
            $row['role_id'] === null ? null : (string) $row['role_id'],
            (string) $row['role_name_ar'],
            (string) $row['role_name_en'],
            $isAdmin,
            $open && $allStores,
            $open && ! $allStores ? $this->storesOf($row) : [],
            $open ? (string) $row['joined_at'] : null,
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
}
