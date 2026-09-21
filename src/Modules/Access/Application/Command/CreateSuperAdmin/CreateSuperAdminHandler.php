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
use Modules\Access\Application\Staff\Invitations;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Exception\StaffEmailInUse;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Domain\Repository\NotificationPreferenceRepository;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\RoleRepository;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\Language;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Access\Domain\ValueObject\StaffProfile;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Access\Public\Events\StaffActivated;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * A new Super Admin gets an invitation that works 24 hours (amendment 30). An existing staff member
 * is promoted: their role is removed, because a Super Admin has none; a disabled one is enabled;
 * one who never accepted gets a fresh Super Admin invitation (owner's decisions, 2026-09-19).
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
        private CustomerRepository $customers,
        private RoleRepository $roles,
        private RoleAssignmentRepository $assignments,
        private StaffTokenRepository $tokens,
        private NotificationPreferenceRepository $preferences,
        private GrantsReader $grants,
        private Invitations $invitations,
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

        // One email, one account (amendment 13): the console is no exception, and a customer's
        // address is taken as surely as a colleague's (review of step 7).
        if ($this->customers->emailInUse($email)) {
            throw new StaffEmailInUse;
        }

        if ($this->staff->phoneInUse($phone)) {
            throw new PhoneAlreadyInUse;
        }

        $staff = StaffUser::invite($this->staff->nextId(), $email, $profile, $phone, Language::of((string) $command->locale), null, superAdmin: true);
        $this->staff->add($staff);
        $this->preferences->createDefaults($staff->id());
        $this->platform->recordAudit(StaffAudit::invited($staff));
        $this->invitations->send($staff, null);
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

        // Nobody else changes a Super Admin's email: a change an admin asked for earlier dies.
        $this->tokens->deleteEmailChange($staff->id());

        if ($staff->status() === StaffStatus::Disabled) {
            $staff->enable();
        }

        $changed = $staff->pullChanges();

        if ($changed !== []) {
            $this->staff->update($staff);
            $this->platform->recordAudit(StaffAudit::updated('access.staff_user.super_admin_granted', $before, $staff, $changed));
        }

        $this->grants->refresh($staff->id());

        // Someone who never accepted gets a Super Admin's invitation: 24 hours (amendment 30).
        if ($staff->status() === StaffStatus::Invited) {
            $this->invitations->send($staff, null);
        } elseif ($before->status() === StaffStatus::Disabled) {
            $this->events->dispatch(new StaffActivated((string) Str::uuid(), $staff->id(), CarbonImmutable::now()));
        }
    }
}
