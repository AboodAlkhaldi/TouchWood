<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\AcceptStaffInvitation;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\PasswordPolicy;
use Modules\Access\Application\Security\PhoneVerification;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\Exception\InvalidOrExpiredLink;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\PhoneCodePurpose;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Access\Public\Enums\StaffStatus;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

final readonly class AcceptStaffInvitationHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_ACCEPT_INVITATION;

    public function __construct(
        private Authorizer $authorizer,
        private StaffUserRepository $staff,
        private StaffTokenRepository $tokens,
        private PasswordPolicy $passwords,
        private PhoneVerification $verification,
        private StaffSecuritySettings $settings,
        private Connection $db,
    ) {}

    public function handle(AcceptStaffInvitation $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $phone = PhoneNumber::of($command->phone);
        // Before any lock: the breach check asks an outside service.
        $passwordHash = $this->passwords->hashNew($command->password, $this->settings->passwordMinLength());

        $this->db->transaction(function () use ($command, $phone, $passwordHash): void {
            $now = CarbonImmutable::now();
            $invitation = $this->tokens->invitationByToken(SecretTokens::hash($command->token));

            if ($invitation === null || $invitation->isExpired($now)) {
                throw new InvalidOrExpiredLink;
            }

            $staff = $this->staff->byId($invitation->staffId);

            if ($staff === null || $staff->status() !== StaffStatus::Invited) {
                throw new InvalidOrExpiredLink;
            }

            if ($this->staff->phoneInUse($phone, $staff->id())) {
                throw new PhoneAlreadyInUse;
            }

            $this->tokens->setPendingPassword($staff->id(), $passwordHash);
            $this->verification->send($staff->id(), $phone, PhoneCodePurpose::Accept, $staff->language(), $now);
        });
    }
}
