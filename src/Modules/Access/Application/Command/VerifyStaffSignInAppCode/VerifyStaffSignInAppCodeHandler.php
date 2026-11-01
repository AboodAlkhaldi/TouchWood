<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\VerifyStaffSignInAppCode;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\AppCodeLimits;
use Modules\Access\Application\Security\AuthenticatorCodes;
use Modules\Access\Application\Security\Codes;
use Modules\Access\Application\Security\RecoveryCodes;
use Modules\Access\Application\Security\ServedPhones;
use Modules\Access\Application\Security\StaffSignInMethods;
use Modules\Access\Application\Session\StaffSessions;
use Modules\Access\Application\Session\TrustedBrowsers;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Application\Staff\StaffMapper;
use Modules\Access\Domain\Exception\AppCodeTriesUsedUp;
use Modules\Access\Domain\Exception\AppSignInLocked;
use Modules\Access\Domain\Exception\InvalidCode;
use Modules\Access\Domain\Exception\SignInRefused;
use Modules\Access\Domain\Exception\WrongAppCode;
use Modules\Access\Domain\Model\StaffAuthenticator;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\Repository\StaffAuthenticatorRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\SignInMethod;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Throwable;

/**
 * The right app code - or an unused recovery code - signs them in (amendment 91). Wrong ones follow
 * the SMS codes' limit: after "Tries at a Code" of them the sign-in ends and the password is asked
 * again. A code is never taken twice: the step it belongs to is kept, and that step or an older one is
 * refused.
 *
 * A sign-in that runs out of codes is audited and told to the person's email at once; the third in 24
 * hours locks the app step, recovery codes included, until the password is reset (owner, 2026-10-31).
 */
final readonly class VerifyStaffSignInAppCodeHandler
{
    public const string PERMISSION = AccessPermissions::SESSION_SIGN_IN;

    /** Used-up sign-ins that lock the app step, and the hours they are counted over (owner, 2026-10-31). */
    public const int LOCK_AFTER_ROUNDS = 3;

    public const int ROUNDS_HOURS = 24;

    public function __construct(
        private Authorizer $authorizer,
        private StaffUserRepository $staff,
        private StaffAuthenticatorRepository $authenticators,
        private AuthenticatorCodes $codes,
        private Codes $hashes,
        private StaffSignInMethods $methods,
        private StaffSecuritySettings $settings,
        private AppCodeLimits $limits,
        private StaffSessions $sessions,
        private TrustedBrowsers $trusted,
        private PlatformApi $platform,
        private Connection $db,
        private ServedPhones $phones,
        private SecurityMessages $messages,
    ) {}

    /**
     * @return array{token: string, hours: int}|null the trust cookie to set, when the browser is trusted
     *
     * @throws InvalidCode|WrongAppCode|AppCodeTriesUsedUp|AppSignInLocked|SignInRefused
     */
    public function handle(VerifyStaffSignInAppCode $command): ?array
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $pending = $this->sessions->pendingSignIn();

        if ($pending === null || ! $pending->byApp) {
            throw new InvalidCode(requestNewCode: true);
        }

        $started = false;

        // A wrong code is counted and committed before it is refused: a rolled-back count would let
        // a code be guessed without limit.
        try {
            $result = $this->db->transaction(function () use ($command, $pending, &$started): array|WrongAppCode|AppCodeTriesUsedUp|AppSignInLocked {
                $staff = $this->staff->byId($pending->staffId);

                if ($staff === null || $staff->status() !== StaffStatus::Active) {
                    throw new SignInRefused;
                }

                // Locked: two codes sent at once are counted, and a step used, one after the other.
                $app = $this->authenticators->forStaff($staff->id(), lock: true);

                // A password changed, or the app reset or no longer their method since the password:
                // the sign-in starts again.
                if (! $pending->stillFor($staff, $this->phones) || $app === null || $app->secret === null
                    || $this->methods->for($staff->id(), $app) !== SignInMethod::Authenticator) {
                    throw new InvalidCode(requestNewCode: true);
                }

                if ($app->isLocked()) {
                    return new AppSignInLocked;
                }

                if ($app->attempts >= $this->settings->codeAttempts()) {
                    return new AppCodeTriesUsedUp;
                }

                $now = CarbonImmutable::now();
                $used = $command->recovery
                    ? $this->authenticators->useRecoveryCode($staff->id(), $this->hashes->hash($staff->id(), RecoveryCodes::normalize($command->code)), $now)
                    : $this->useAppCode($staff->id(), $app->secret, $command->code, $app->lastUsedStep, $now);

                if (! $used) {
                    $this->authenticators->countAttempt($staff->id());

                    if ($app->attempts + 1 < $this->settings->codeAttempts()) {
                        return new WrongAppCode;
                    }

                    return $this->usedUp($staff, $app, $now);
                }

                $this->authenticators->resetAttempts($staff->id());

                // Signed in first, so the entries name them as the actor, with their address.
                $this->sessions->start($staff->id(), $staff->sessionVersion());
                $started = true;
                $this->platform->recordAudit(StaffAudit::event('access.staff_user.signed_in', $staff, ['trusted_browser' => false]));

                if ($command->recovery) {
                    $this->platform->recordAudit(StaffAudit::event('access.staff_user.recovery_code_used', $staff, [
                        'recovery_codes_left' => (string) $this->authenticators->recoveryCodesLeft($staff->id()),
                    ]));
                }

                if (! $command->trustBrowser) {
                    return ['trust' => null];
                }

                $trust = $this->trusted->trust($staff->id());
                $this->platform->recordAudit(StaffAudit::event('access.staff_user.browser_trusted', $staff));

                return ['trust' => $trust];
            }, 3);
        } catch (Throwable $failure) {
            // The session is started inside the transaction so the entries name them as the actor; a
            // transaction that then rolls back must leave no session behind (as VerifyStaffSignInCode).
            if ($started) {
                $this->sessions->end();
            }

            throw $failure;
        }

        if (! is_array($result)) {
            // An attempt that deadlocked after signing them in and then ran again may leave a session
            // behind a refusal; and too many wrong codes end this sign-in, so the password is asked
            // again (decision 5).
            if ($started || ! $result instanceof WrongAppCode) {
                $this->sessions->end();
            }

            throw $result;
        }

        // The hour's sign-ins by app are given back once one succeeds (amendment 91 (c)).
        $this->limits->succeeded($pending->staffId);

        return $result['trust'];
    }

    /**
     * A sign-in out of codes: counted towards the lock, audited, and told to the person once it commits.
     */
    private function usedUp(StaffUser $staff, StaffAuthenticator $app, CarbonImmutable $now): AppCodeTriesUsedUp
    {
        // Counted over 24 hours from the first of them; an older count starts again.
        $since = $app->roundsSince;
        $rounds = $app->usedUpRounds + 1;

        if ($since === null || $now->getTimestamp() - $since->getTimestamp() > self::ROUNDS_HOURS * 3600) {
            $since = $now;
            $rounds = 1;
        }

        $locked = $rounds >= self::LOCK_AFTER_ROUNDS;
        $this->authenticators->recordRounds($staff->id(), $rounds, $since, $locked ? $now : null);
        $this->platform->recordAudit(StaffAudit::event('access.staff_user.app_code_tries_used_up', $staff, ['rounds_in_24_hours' => (string) $rounds]));

        if ($locked) {
            $this->platform->recordAudit(StaffAudit::event('access.staff_user.app_sign_in_locked', $staff));
        }

        $dto = StaffMapper::toDto($staff);
        // A failed notice goes to the log and never replaces the answer below (amendment 93).
        $this->db->afterCommit(fn () => rescue(fn () => $this->messages->staffAppCodeFailed($dto, $now, $locked)));

        return new AppCodeTriesUsedUp;
    }

    private function useAppCode(string $staffId, string $secret, string $code, ?int $lastUsedStep, CarbonImmutable $now): bool
    {
        $step = $this->codes->verify($secret, $code, $lastUsedStep, $now);

        if ($step === null) {
            return false;
        }

        $this->authenticators->useStep($staffId, $step);

        return true;
    }
}
