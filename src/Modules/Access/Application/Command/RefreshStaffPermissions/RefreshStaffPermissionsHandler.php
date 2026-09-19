<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RefreshStaffPermissions;

use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Shared\Application\Authorizer;

/**
 * Allowed to whoever may change this staff member's role. Changes no data, so nothing is audited.
 */
final readonly class RefreshStaffPermissionsHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_ASSIGN_ROLE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private RoleAssignmentRepository $assignments,
        private GrantsReader $grants,
    ) {}

    public function handle(RefreshStaffPermissions $command): void
    {
        $target = $this->staff->byId($command->staffId) ?? throw new StaffNotFound($command->staffId);
        $stores = $this->assignments->byStaff($target->id())?->staffStores();
        $scopes = $this->rules->scopesFor($stores);

        if ($scopes === []) {
            $this->rules->requireSomewhere(self::PERMISSION);
        }

        foreach ($scopes as $scope) {
            $this->authorizer->authorize(self::PERMISSION, $scope);
        }

        $this->rules->requireManageable($this->rules->author(), $target, $this->grants->forStaff($target->id()));
        $this->grants->refresh($target->id());
    }
}
