<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ResendStaffSignInCode;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\PhoneVerification;
use Modules\Access\Application\Session\StaffSessions;
use Modules\Access\Domain\Exception\InvalidCode;
use Modules\Access\Domain\Exception\SignInRefused;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\PhoneCodePurpose;
use Modules\Access\Public\Enums\StaffStatus;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * To the number the last code went to — their phone, or the new number a Super Admin entered.
 */
final readonly class ResendStaffSignInCodeHandler
{
    public const string PERMISSION = AccessPermissions::SESSION_SIGN_IN;

    public function __construct(
        private Authorizer $authorizer,
        private StaffUserRepository $staff,
        private StaffTokenRepository $tokens,
        private PhoneVerification $verification,
        private StaffSessions $sessions,
        private Connection $db,
    ) {}

    public function handle(ResendStaffSignInCode $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $pending = $this->sessions->pendingSignIn() ?? throw new InvalidCode(requestNewCode: true);

        $this->db->transaction(function () use ($pending): void {
            $staff = $this->staff->byId($pending->staffId);

            if ($staff === null || $staff->status() !== StaffStatus::Active) {
                throw new SignInRefused;
            }

            if (! $pending->stillFor($staff)) {
                throw new InvalidCode(requestNewCode: true);
            }

            // The account's number as it is now; only a Super Admin choosing one gets the number
            // they entered (review of step 3b: never a number an admin has since replaced).
            $phone = $pending->needsPhone
                ? $this->tokens->phoneCode($staff->id(), PhoneCodePurpose::SignIn)?->phone
                : $staff->phone();

            if ($phone === null) {
                throw new InvalidCode(requestNewCode: true);
            }

            $this->verification->send($staff->id(), $phone, PhoneCodePurpose::SignIn, $staff->language(), CarbonImmutable::now());
        });
    }
}
