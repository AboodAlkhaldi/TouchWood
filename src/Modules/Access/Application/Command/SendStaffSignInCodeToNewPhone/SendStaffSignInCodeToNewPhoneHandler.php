<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SendStaffSignInCodeToNewPhone;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\PhoneVerification;
use Modules\Access\Application\Session\StaffSessions;
use Modules\Access\Domain\Exception\InvalidCode;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Exception\SignInRefused;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\PhoneCodePurpose;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Access\Public\Enums\StaffStatus;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

final readonly class SendStaffSignInCodeToNewPhoneHandler
{
    public const string PERMISSION = AccessPermissions::SESSION_SIGN_IN;

    public function __construct(
        private Authorizer $authorizer,
        private StaffUserRepository $staff,
        private PhoneVerification $verification,
        private StaffSessions $sessions,
        private Connection $db,
    ) {}

    public function handle(SendStaffSignInCodeToNewPhone $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $pending = $this->sessions->pendingSignIn();

        // Only after the right password, and only for someone with no phone at all.
        if ($pending === null || ! $pending->needsPhone) {
            throw new InvalidCode(requestNewCode: true);
        }

        $phone = PhoneNumber::of($command->phone);

        $this->db->transaction(function () use ($pending, $phone): void {
            $staff = $this->staff->byId($pending->staffId);

            if ($staff === null || $staff->status() !== StaffStatus::Active) {
                throw new SignInRefused;
            }

            // Still a Super Admin with no phone, under the same password (review of step 3b).
            if (! $pending->stillFor($staff)) {
                throw new InvalidCode(requestNewCode: true);
            }

            // Refused when the number is entered, and again when the code comes back.
            if ($this->staff->phoneInUse($phone, $staff->id())) {
                throw new PhoneAlreadyInUse;
            }

            $this->verification->send($staff->id(), $phone, PhoneCodePurpose::SignIn, $staff->language(), CarbonImmutable::now());
            // The code screen names the number it went to, masked, here as everywhere else the
            // screen appears (stage 2b, P4).
            $this->sessions->noteCodeSentTo($phone->masked());
        });
    }
}
