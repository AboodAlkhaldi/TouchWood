<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SignInStaff;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\PasswordPolicy;
use Modules\Access\Application\Security\PhoneVerification;
use Modules\Access\Application\Security\SignInLimits;
use Modules\Access\Application\Session\StaffSessions;
use Modules\Access\Application\Session\TrustedBrowsers;
use Modules\Access\Domain\Exception\AccountLocked;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidCredentials;
use Modules\Access\Domain\Exception\SignInRefused;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\PhoneCodePurpose;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Spec §1.8, §4.4: the password, then — unless this browser is trusted — an SMS code. A wrong
 * email or password is answered the same way, and only the right password learns that an account
 * is disabled.
 */
final readonly class SignInStaffHandler
{
    public const string PERMISSION = AccessPermissions::SESSION_SIGN_IN;

    public function __construct(
        private Authorizer $authorizer,
        private StaffUserRepository $staff,
        private PasswordPolicy $passwords,
        private SignInLimits $limits,
        private TrustedBrowsers $trusted,
        private PhoneVerification $verification,
        private StaffSessions $sessions,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /**
     * @throws InvalidCredentials|AccountLocked|SignInRefused
     */
    public function handle(SignInStaff $command): SignInResult
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $this->limits->requireOpen($command->email, $command->ip);

        $staff = $this->account($command->email);

        if (! $this->passwords->matches($command->password, $staff?->passwordHash())) {
            if ($this->limits->failed($command->email, $command->ip) && $staff !== null) {
                $this->db->transaction(fn () => $this->platform->recordAudit(StaffAudit::event('access.staff_user.locked_out', $staff)));
            }

            throw new InvalidCredentials;
        }

        // A password is stored only once an invitation is accepted, so $staff is set here.
        if ($staff === null || $staff->status() !== StaffStatus::Active) {
            throw new SignInRefused;
        }

        $this->limits->succeeded($command->email);

        $trustedBrowser = $this->db->transaction(function () use ($staff, $command): bool {
            if (! $this->trusted->trusts($staff->id(), $command->trustToken)) {
                return false;
            }

            // Signed in first, so the entry names them as the actor, with their address.
            $this->sessions->start($staff->id(), $staff->sessionVersion());
            $this->platform->recordAudit(StaffAudit::event('access.staff_user.signed_in', $staff, ['trusted_browser' => true]));

            return true;
        });

        if ($trustedBrowser) {
            return SignInResult::SignedIn;
        }

        $phone = $staff->phone();

        if ($phone === null) {
            $this->sessions->beginSignIn($staff->id(), true);

            return SignInResult::PhoneNeeded;
        }

        $this->db->transaction(fn () => $this->verification->send($staff->id(), $phone, PhoneCodePurpose::SignIn, $staff->language(), CarbonImmutable::now()));
        $this->sessions->beginSignIn($staff->id(), false);

        return SignInResult::CodeSent;
    }

    private function account(string $email): ?StaffUser
    {
        try {
            return $this->staff->byEmail(EmailAddress::of($email));
        } catch (InvalidAccessAttribute) {
            return null;
        }
    }
}
