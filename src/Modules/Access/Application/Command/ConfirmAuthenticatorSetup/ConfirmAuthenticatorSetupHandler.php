<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ConfirmAuthenticatorSetup;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\AuthenticatorCodes;
use Modules\Access\Application\Security\Codes;
use Modules\Access\Application\Security\RecoveryCodes;
use Modules\Access\Application\Session\StaffSessions;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\Exception\AuthenticatorSetupExpired;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Exception\WrongAppCode;
use Modules\Access\Domain\Repository\StaffAuthenticatorRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The first code from the app makes it the one in use (amendment 91): it replaces an old app only now,
 * comes with ten new recovery codes, returned to be shown once, and lets a session that had to set an
 * app up into the panel. Wrong codes follow the SMS codes' limit: after "Tries at a Code" the setup
 * ends, and the password is asked again for a new QR code.
 */
final readonly class ConfirmAuthenticatorSetupHandler
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    /** How long a QR code is good after the password: as the code step after a password (amendment 34). */
    public const int MINUTES = 15;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private StaffAuthenticatorRepository $authenticators,
        private AuthenticatorCodes $codes,
        private Codes $hashes,
        private StaffSecuritySettings $settings,
        private StaffSessions $sessions,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /**
     * @return list<string> the ten recovery codes, to be shown once and never again
     *
     * @throws WrongAppCode|AuthenticatorSetupExpired
     */
    public function handle(ConfirmAuthenticatorSetup $command): array
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();
        $openedHere = $this->sessions->authenticatorSetupOpenedAt();

        // A wrong code is counted and committed before it is refused.
        $result = $this->db->transaction(function () use ($command, $staffId, $openedHere): array|WrongAppCode|AuthenticatorSetupExpired {
            $staff = $this->staff->byId($staffId) ?? throw new StaffNotFound($staffId);
            $app = $this->authenticators->forStaff($staffId, lock: true);
            $now = CarbonImmutable::now();

            // Only the setup this browser opened with the password, while it is good.
            if ($app === null || $app->pendingSecret === null || $app->pendingAt === null) {
                return new AuthenticatorSetupExpired;
            }

            // Past its time or its tries, it goes; one opened in another browser stays for that browser.
            if (! $app->hasPendingSince($now, self::MINUTES) || $app->pendingAttempts >= $this->settings->codeAttempts()) {
                $this->authenticators->dropSetup($staffId);

                return new AuthenticatorSetupExpired;
            }

            if ($openedHere !== $app->pendingAt->getTimestamp()) {
                return new AuthenticatorSetupExpired;
            }

            $step = $this->codes->verify($app->pendingSecret, $command->code, null, $now);

            if ($step === null) {
                if ($app->pendingAttempts + 1 >= $this->settings->codeAttempts()) {
                    $this->authenticators->dropSetup($staffId);

                    return new AuthenticatorSetupExpired;
                }

                $this->authenticators->countSetupAttempt($staffId);

                return new WrongAppCode;
            }

            $this->authenticators->confirmSetup($staffId, $step, $now);
            $codes = RecoveryCodes::generate();
            $this->authenticators->replaceRecoveryCodes($staffId, array_map(fn (string $code): string => $this->hashes->hash($staffId, RecoveryCodes::normalize($code)), $codes), $now);
            $this->platform->recordAudit(StaffAudit::event('access.staff_user.authenticator_set_up', $staff, ['replaced' => $app->isConfirmed()]));

            return $codes;
        }, 3);

        if (! is_array($result)) {
            throw $result;
        }

        $this->sessions->requireAuthenticatorSetup(false);

        return $result;
    }
}
