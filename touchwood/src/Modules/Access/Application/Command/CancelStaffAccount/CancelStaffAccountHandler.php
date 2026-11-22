<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CancelStaffAccount;

use Illuminate\Database\Connection;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Staff\StaffCancellation;
use Modules\Access\Domain\Exception\InvalidStaffStatus;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Public\Enums\StaffStatus;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * Only the admin who invited them — while they may still invite staff in all of the person's
 * stores — or a Super Admin (owner's decision, 2026-09-19). A Super Admin's invitation is
 * cancelled only from the console.
 */
final readonly class CancelStaffAccountHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_INVITE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private RoleAssignmentRepository $assignments,
        private GrantsReader $grants,
        private StaffCancellation $cancellation,
        private Connection $db,
    ) {}

    public function handle(CancelStaffAccount $command): void
    {
        $this->rules->requireSomewhere(self::PERMISSION);

        $this->db->transaction(function () use ($command): void {
            $target = $this->staff->byId($command->staffId) ?? throw new StaffNotFound($command->staffId);

            foreach ($this->rules->scopesFor($this->assignments->byStaff($target->id())?->staffStores()) as $scope) {
                $this->authorizer->authorize(self::PERMISSION, $scope);
            }

            $author = $this->rules->author();
            $this->rules->requireManageable($author, $target, $this->grants->forStaff($target->id()));

            if (! $author->isUnlimited() && $author->staffId !== $target->invitedBy()) {
                throw new Unauthorized(self::PERMISSION);
            }

            if ($target->status() !== StaffStatus::Invited) {
                throw new InvalidStaffStatus($target->status());
            }

            $this->cancellation->cancel($target);
        }, 3);
    }
}
