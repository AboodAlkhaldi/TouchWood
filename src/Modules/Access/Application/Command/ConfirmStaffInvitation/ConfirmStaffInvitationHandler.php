<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ConfirmStaffInvitation;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\PhoneVerification;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Domain\Exception\InvalidCode;
use Modules\Access\Domain\Exception\InvalidOrExpiredLink;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\PhoneCodePurpose;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Access\Public\Events\StaffActivated;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

final readonly class ConfirmStaffInvitationHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_ACCEPT_INVITATION;

    public function __construct(
        private Authorizer $authorizer,
        private StaffUserRepository $staff,
        private StaffTokenRepository $tokens,
        private PhoneVerification $verification,
        private GrantsReader $grants,
        private PlatformApi $platform,
        private Dispatcher $events,
        private Connection $db,
    ) {}

    public function handle(ConfirmStaffInvitation $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        // A wrong code is counted and committed before the error is thrown (PhoneVerification).
        $failure = $this->db->transaction(function () use ($command): ?InvalidCode {
            $now = CarbonImmutable::now();
            $invitation = $this->tokens->invitationByToken(SecretTokens::hash($command->token));

            if ($invitation === null || $invitation->isExpired($now) || $invitation->pendingPasswordHash === null) {
                throw new InvalidOrExpiredLink;
            }

            $staff = $this->staff->byId($invitation->staffId);

            if ($staff === null || $staff->status() !== StaffStatus::Invited) {
                throw new InvalidOrExpiredLink;
            }

            $phone = $this->verification->check($staff->id(), PhoneCodePurpose::Accept, $command->code, $now);

            if ($phone instanceof InvalidCode) {
                return $phone;
            }

            if ($this->staff->phoneInUse($phone, $staff->id())) {
                throw new PhoneAlreadyInUse;
            }

            $before = clone $staff;
            $staff->accept($invitation->pendingPasswordHash, $phone, $now);
            $this->staff->update($staff);
            $this->tokens->deleteInvitation($staff->id());
            $this->platform->recordAudit(StaffAudit::updated('access.staff_user.accepted', $before, $staff, $staff->pullChanges()));
            $this->grants->refresh($staff->id());

            $this->events->dispatch(new StaffActivated((string) Str::uuid(), $staff->id(), $now));

            return null;
        });

        if ($failure !== null) {
            throw $failure;
        }
    }
}
