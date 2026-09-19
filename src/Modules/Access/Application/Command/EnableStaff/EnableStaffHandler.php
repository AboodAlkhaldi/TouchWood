<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\EnableStaff;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Command\ChangeStaffRole\ChangeStaffRoleHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Application\Staff\StaffLinks;
use Modules\Access\Application\Staff\StaffMapper;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Access\Public\Events\StaffActivated;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;

final readonly class EnableStaffHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_DISABLE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private RoleAssignmentRepository $assignments,
        private GrantsReader $grants,
        private ChangeStaffRoleHandler $roles,
        private StaffTokenRepository $tokens,
        private StaffSecuritySettings $settings,
        private SecurityMessages $messages,
        private StaffLinks $links,
        private PlatformApi $platform,
        private Dispatcher $events,
        private Connection $db,
    ) {}

    public function handle(EnableStaff $command): void
    {
        $this->rules->requireSomewhere(self::PERMISSION);

        $this->db->transaction(function () use ($command): void {
            // The role first, under every rule of giving one — it locks roles before staff — so the
            // checks below see the stores it gives. A refusal below undoes it.
            if ($command->role !== null) {
                if ($command->role->staffId !== $command->staffId) {
                    throw new InvalidAccessAttribute('role', 'given for another staff member');
                }

                $this->roles->handle($command->role);
            }

            $target = $this->staff->byId($command->staffId) ?? throw new StaffNotFound($command->staffId);
            $assignment = $this->assignments->byStaff($target->id());

            if ($assignment === null && ! $target->isSuperAdmin()) {
                throw new InvalidAccessAttribute('role', 'someone with no role is enabled only together with one');
            }

            foreach ($this->rules->scopesFor($assignment?->staffStores()) as $scope) {
                $this->authorizer->authorize(self::PERMISSION, $scope);
            }

            $author = $this->rules->author();
            $this->rules->requireManageable($author, $target, $this->grants->forStaff($target->id()));

            $before = clone $target;
            $target->enable();
            $this->staff->update($target);
            $this->platform->recordAudit(StaffAudit::updated('access.staff_user.enabled', $before, $target, $target->pullChanges()));
            $this->grants->refresh($target->id());

            if ($target->status() === StaffStatus::Active) {
                $this->events->dispatch(new StaffActivated((string) Str::uuid(), $target->id(), CarbonImmutable::now()));

                return;
            }

            // Never accepted: a new invitation, as the first one was cancelled.
            $invitation = SecretTokens::issue();
            $this->tokens->putInvitation($target->id(), $invitation['hash'], CarbonImmutable::now()->addHours($this->settings->invitationHours()), $author->staffId);
            $this->db->afterCommit(fn () => $this->messages->staffInvitation(StaffMapper::toDto($target), $this->links->invitation($invitation['token'])));
        }, 3);
    }
}
