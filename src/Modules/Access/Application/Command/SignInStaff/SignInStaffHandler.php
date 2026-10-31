<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SignInStaff;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\AppCodeLimits;
use Modules\Access\Application\Security\Codes;
use Modules\Access\Application\Security\PasswordPolicy;
use Modules\Access\Application\Security\PhoneVerification;
use Modules\Access\Application\Security\ServedPhones;
use Modules\Access\Application\Security\SignInLimits;
use Modules\Access\Application\Security\StaffSignInMethods;
use Modules\Access\Application\Session\StaffSessions;
use Modules\Access\Application\Session\TrustedBrowsers;
use Modules\Access\Domain\Exception\AccountLocked;
use Modules\Access\Domain\Exception\AppSignInLocked;
use Modules\Access\Domain\Exception\AppSignInsTooSoon;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidCredentials;
use Modules\Access\Domain\Exception\SignInRefused;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\Repository\StaffAuthenticatorRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\PhoneCodePurpose;
use Modules\Access\Domain\ValueObject\SignInMethod;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Throwable;

/**
 * Spec §1.8, §4.4: the password, then — unless this browser is trusted — an SMS code, or a code from
 * the authenticator app for someone whose method it is and who has set it up (amendment 91). A wrong
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
        private Codes $codes,
        private TrustedBrowsers $trusted,
        private PhoneVerification $verification,
        private StaffSessions $sessions,
        private PlatformApi $platform,
        private Connection $db,
        private ServedPhones $phones,
        private StaffSignInMethods $methods,
        private StaffAuthenticatorRepository $authenticators,
        private AppCodeLimits $appLimits,
    ) {}

    /**
     * @throws InvalidCredentials|AccountLocked|SignInRefused|AppSignInsTooSoon|AppSignInLocked
     */
    public function handle(SignInStaff $command): SignInResult
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $this->limits->begin($command->email, $command->ip);

        $staff = $this->account($command->email);

        if (! $this->passwords->matches($command->password, $staff?->passwordHash())) {
            $locked = $this->limits->failed($command->email, $command->ip);

            // Lockouts are audited, the wrong passwords before them only counted (amendment 32).
            if ($locked['account'] || $locked['address']) {
                $this->db->transaction(function () use ($locked, $staff, $command): void {
                    if ($locked['account'] && $staff !== null) {
                        $this->platform->recordAudit(StaffAudit::event('access.staff_user.locked_out', $staff));
                    }

                    if ($locked['address']) {
                        $this->platform->recordAudit(StaffAudit::addressLocked($this->codes->hash('sign-in-address', $command->ip)));
                    }
                });
            }

            throw new InvalidCredentials;
        }

        $this->limits->succeeded($command->email, $command->ip);

        // A password is stored only once an invitation is accepted, so $staff is set here.
        if ($staff === null || $staff->status() !== StaffStatus::Active) {
            throw new SignInRefused;
        }

        $started = false;

        try {
            $trustedBrowser = $this->db->transaction(function () use ($staff, $command, &$started): bool {
                if (! $this->trusted->trusts($staff->id(), $command->trustToken)) {
                    return false;
                }

                // Read again under the row's lock: checking the password takes long enough for an
                // admin to disable the account, or a reset to raise the version, in between — the
                // read above was for the password and the id only (review of step 7).
                $current = $this->staff->byId($staff->id());

                if ($current === null || $current->status() !== StaffStatus::Active) {
                    throw new SignInRefused;
                }

                // Signed in first, so the entry names them as the actor, with their address. A trusted
                // browser skips the second step as before, but not the app's setup (amendment 91).
                $this->sessions->start($current->id(), $current->sessionVersion(), $this->methods->mustSetUp($current->id()));
                $started = true;
                $this->platform->recordAudit(StaffAudit::event('access.staff_user.signed_in', $current, ['trusted_browser' => true]));

                return true;
            }, 3);
        } catch (Throwable $failure) {
            // The session is started inside the transaction so the entry names them as the actor;
            // a transaction that then rolls back must leave no session behind (review of step 7).
            if ($started) {
                $this->sessions->end();
            }

            throw $failure;
        }

        if ($trustedBrowser) {
            return SignInResult::SignedIn;
        }

        // An attempt that deadlocked after signing them in, and then found the browser untrusted
        // when it ran again, must not leave that session behind: the code step follows (step 7).
        if ($started) {
            $this->sessions->end();
        }

        // Their method is the app and it is set up: a code from it, not an SMS (amendment 91). Without
        // one set up, the SMS code comes first and the setup right after (decision 2).
        $app = $this->authenticators->forStaff($staff->id());

        if ($app !== null && $app->isConfirmed() && $this->methods->for($staff->id(), $app) === SignInMethod::Authenticator) {
            // Three sign-ins in 24 hours ran out of codes: locked until the password is reset (owner,
            // 2026-10-31). Said only after the right password, as a disabled account is.
            if ($app->isLocked()) {
                throw new AppSignInLocked;
            }

            // As often an hour as an SMS code goes to one number (decision 5).
            $this->appLimits->open($staff->id());
            $this->authenticators->resetAttempts($staff->id());
            $this->sessions->beginSignIn($staff->id(), $staff->sessionVersion(), false, null, byApp: true);

            return SignInResult::AppCodeNeeded;
        }

        // A phone whose country was taken off the SMS countries list counts as none: no code can reach
        // it (amendment 90).
        $phone = $this->phones->reaches($staff->phone()) ? $staff->phone() : null;

        if ($phone === null) {
            // Only a Super Admin whose phone was reset, or whose phone SMS no longer reaches, chooses a
            // new number here (amendments 14, 90); anyone else would pass the code step with the
            // password alone. An admin gives them one.
            if (! $staff->isSuperAdmin()) {
                throw new SignInRefused;
            }

            $this->sessions->beginSignIn($staff->id(), $staff->sessionVersion(), true);

            return SignInResult::PhoneNeeded;
        }

        $this->db->transaction(fn () => $this->verification->send($staff->id(), $phone, PhoneCodePurpose::SignIn, $staff->language(), CarbonImmutable::now()));
        // The code screen names the number it went to, masked (stage 2b, P4).
        $this->sessions->beginSignIn($staff->id(), $staff->sessionVersion(), false, $phone->masked());

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
