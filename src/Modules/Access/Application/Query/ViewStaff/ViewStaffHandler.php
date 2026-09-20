<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ViewStaff;

use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Query\ListStaff\StaffSummary;
use Modules\Access\Application\Query\StaffReader;
use Modules\Access\Application\Query\StaffVisibility;
use Modules\Access\Domain\Exception\StaffNotFound;
use Shared\Application\Authorizer;

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
        private StaffVisibility $visibility,
    ) {}

    /**
     * @throws StaffNotFound
     */
    public function handle(ViewStaff $query): StaffSummary
    {
        $stores = $this->authorizer->storesWith(self::PERMISSION);
        $row = $stores === [] ? null : $this->staff->member($query->staffId);
        $unlimited = $this->rules->author()->isUnlimited();

        if ($row === null || ($row['is_super_admin'] === true && ! $unlimited)) {
            throw new StaffNotFound($query->staffId);
        }

        if (! $unlimited && ! $this->visibility->covers($stores, $row)) {
            throw new StaffNotFound($query->staffId);
        }

        return $this->visibility->summary($row, $unlimited);
    }
}
