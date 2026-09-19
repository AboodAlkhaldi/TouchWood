<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\DisableStaff;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Public\Events\StaffDisabled;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;

final readonly class DisableStaffHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_DISABLE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private RoleAssignmentRepository $assignments,
        private GrantsReader $grants,
        private StaffTokenRepository $tokens,
        private PlatformApi $platform,
        private Dispatcher $events,
        private Connection $db,
    ) {}

    public function handle(DisableStaff $command): void
    {
        $this->rules->requireSomewhere(self::PERMISSION);

        $this->db->transaction(function () use ($command): void {
            $target = $this->staff->byId($command->staffId) ?? throw new StaffNotFound($command->staffId);

            foreach ($this->rules->scopesFor($this->assignments->byStaff($target->id())?->staffStores()) as $scope) {
                $this->authorizer->authorize(self::PERMISSION, $scope);
            }

            // Never a Super Admin, an admin (unless by a Super Admin) or yourself.
            $this->rules->requireManageable($this->rules->author(), $target, $this->grants->forStaff($target->id()));

            $before = clone $target;
            $target->disable();
            $this->staff->update($target);
            $this->tokens->deleteInvitation($target->id());
            $this->tokens->deletePhoneCode($target->id());
            $this->tokens->deleteEmailChange($target->id());
            $this->tokens->deletePasswordReset($target->id());
            $this->tokens->forgetTrustedBrowsers($target->id());
            $this->platform->recordAudit(StaffAudit::updated('access.staff_user.disabled', $before, $target, $target->pullChanges()));
            $this->grants->refresh($target->id());

            $this->events->dispatch(new StaffDisabled((string) Str::uuid(), $target->id(), CarbonImmutable::now()));
        });
    }
}
