<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\InviteStaff;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Command\ChangeStaffRole\ActionStores;
use Modules\Access\Application\Command\ChangeStaffRole\ChangeStaffRole;
use Modules\Access\Application\Command\ChangeStaffRole\ChangeStaffRoleHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Application\Staff\StaffLinks;
use Modules\Access\Application\Staff\StaffMapper;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Exception\StaffEmailInUse;
use Modules\Access\Domain\Model\RoleAssignment;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\Repository\NotificationPreferenceRepository;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\Language;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Access\Domain\ValueObject\StaffProfile;
use Modules\Access\Domain\ValueObject\StoreChoice;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;

/**
 * Access spec §3.2 and amendment 15: the admin enters the whole profile and the role; the new staff
 * member gets an invitation link (72 hours) and verifies their phone when accepting.
 *
 * Inviting needs "invite staff" in every store the new member will have. Giving them their role is
 * a ChangeStaffRole, with all its rules — "assign roles" in those stores, nothing more than the
 * inviter holds, admin roles by a Super Admin only — because it gives them their access (amendment
 * 11).
 */
final readonly class InviteStaffHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_INVITE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private StaffTokenRepository $tokens,
        private NotificationPreferenceRepository $preferences,
        private ChangeStaffRoleHandler $roles,
        private StaffSecuritySettings $settings,
        private SecurityMessages $messages,
        private StaffLinks $links,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /**
     * @return string the new staff member's id
     */
    public function handle(InviteStaff $command): string
    {
        $row = StoreChoice::of($command->accessLevel, $command->storeIds);
        $stores = RoleAssignment::assign('new', 'new', $row, ActionStores::toChoices($command->exceptions), null, CarbonImmutable::now())->staffStores();

        foreach ($this->rules->scopesFor($stores) as $scope) {
            $this->authorizer->authorize(self::PERMISSION, $scope);
        }

        $author = $this->rules->author();
        $email = EmailAddress::of($command->email);
        $profile = StaffProfile::of($command->firstName, $command->lastName, $command->jobTitle, $command->dateOfBirth, $command->country, $command->address);
        $phone = PhoneNumber::of($command->phone);
        $language = Language::of($command->locale);

        return $this->db->transaction(function () use ($command, $author, $email, $profile, $phone, $language): string {
            if ($this->staff->emailInUse($email)) {
                throw new StaffEmailInUse;
            }

            if ($this->staff->phoneInUse($phone)) {
                throw new PhoneAlreadyInUse;
            }

            $staff = StaffUser::invite($this->staff->nextId(), $email, $profile, $phone, $language);
            $this->staff->add($staff);
            $this->preferences->createDefaults($staff->id());
            $this->platform->recordAudit(StaffAudit::invited($staff));

            $this->roles->handle(new ChangeStaffRole(
                $staff->id(),
                $command->accessLevel,
                $command->storeIds,
                $command->exceptions,
                $command->savedRoleId,
                $command->personalRole,
            ));

            $invitation = SecretTokens::issue();
            $this->tokens->putInvitation($staff->id(), $invitation['hash'], CarbonImmutable::now()->addHours($this->settings->invitationHours()), $author->staffId);

            // The link never enters an event or a queued job (spec §2.3).
            $this->db->afterCommit(fn () => $this->messages->staffInvitation(StaffMapper::toDto($staff), $this->links->invitation($invitation['token'])));

            return $staff->id();
        });
    }
}
