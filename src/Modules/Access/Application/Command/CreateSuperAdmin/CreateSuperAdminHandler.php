<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CreateSuperAdmin;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Application\Audit\RoleAudit;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Application\Staff\StaffLinks;
use Modules\Access\Application\Staff\StaffMapper;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\Repository\NotificationPreferenceRepository;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\RoleRepository;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\Language;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Access\Domain\ValueObject\StaffProfile;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Access\Public\Events\StaffActivated;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * A new Super Admin gets an invitation (72 hours). An existing staff member is promoted: their role
 * is removed, because a Super Admin has none; a disabled one is enabled; one who never accepted
 * gets a fresh invitation (owner's decision, 2026-09-19).
 */
final readonly class CreateSuperAdminHandler
{
    public const string PERMISSION = AccessPermissions::SUPER_ADMIN_MANAGE;

    public const string CREATED = 'created';

    public const string PROMOTED = 'promoted';

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private RoleRepository $roles,
        private RoleAssignmentRepository $assignments,
        private StaffTokenRepository $tokens,
        private NotificationPreferenceRepository $preferences,
        private GrantsReader $grants,
        private StaffSecuritySettings $settings,
        private SecurityMessages $messages,
        private StaffLinks $links,
        private PlatformApi $platform,
        private Dispatcher $events,
        private Connection $db,
    ) {}

    /**
     * @return self::CREATED|self::PROMOTED
     */
    public function handle(CreateSuperAdmin $command): string
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $this->rules->requireConsole(self::PERMISSION);
        $email = EmailAddress::of($command->email);

        return $this->db->transaction(function () use ($command, $email): string {
            $existing = $this->staff->byEmail($email);

            if ($existing !== null) {
                $this->promote($existing);

                return self::PROMOTED;
            }

            $this->create($command, $email);

            return self::CREATED;
        }, 3);
    }

    private function create(CreateSuperAdmin $command, EmailAddress $email): void
    {
        $profile = StaffProfile::of((string) $command->firstName, (string) $command->lastName, (string) $command->jobTitle, (string) $command->dateOfBirth, (string) $command->country, $command->address);
        $phone = PhoneNumber::of((string) $command->phone);

        if ($this->staff->phoneInUse($phone)) {
            throw new PhoneAlreadyInUse;
        }

        $staff = StaffUser::invite($this->staff->nextId(), $email, $profile, $phone, Language::of($command->locale), superAdmin: true);
        $this->staff->add($staff);
        $this->preferences->createDefaults($staff->id());
        $this->platform->recordAudit(StaffAudit::invited($staff));
        $this->invite($staff);
    }

    private function promote(StaffUser $staff): void
    {
        $before = clone $staff;
        $assignment = $this->assignments->byStaff($staff->id());

        if ($assignment !== null) {
            $this->assignments->delete($staff->id());
            $this->platform->recordAudit(StaffAudit::event('access.staff_user.role_removed', $staff, ['role_id' => $assignment->roleId()]));
        }

        $personal = $this->roles->personalRoleOf($staff->id());

        if ($personal !== null) {
            $this->roles->delete($personal->id());
            $this->platform->recordAudit(RoleAudit::deleted($personal, null, []));
        }

        $staff->promoteToSuperAdmin();

        if ($staff->status() === StaffStatus::Disabled) {
            $staff->enable();
        }

        $changed = $staff->pullChanges();

        if ($changed !== []) {
            $this->staff->update($staff);
            $this->platform->recordAudit(StaffAudit::updated('access.staff_user.super_admin_granted', $before, $staff, $changed));
        }

        $this->grants->refresh($staff->id());

        if ($staff->status() === StaffStatus::Invited) {
            $this->invite($staff);
        } elseif ($before->status() === StaffStatus::Disabled) {
            $this->events->dispatch(new StaffActivated((string) Str::uuid(), $staff->id(), CarbonImmutable::now()));
        }
    }

    private function invite(StaffUser $staff): void
    {
        $invitation = SecretTokens::issue();
        $this->tokens->putInvitation($staff->id(), $invitation['hash'], CarbonImmutable::now()->addHours($this->settings->invitationHours()), null);
        $this->db->afterCommit(fn () => $this->messages->staffInvitation(StaffMapper::toDto($staff), $this->links->invitation($invitation['token'])));
    }
}
