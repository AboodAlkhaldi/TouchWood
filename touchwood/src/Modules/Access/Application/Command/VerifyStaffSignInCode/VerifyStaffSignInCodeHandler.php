<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\VerifyStaffSignInCode;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\PhoneVerification;
use Modules\Access\Application\Security\ServedPhones;
use Modules\Access\Application\Security\StaffSignInMethods;
use Modules\Access\Application\Session\StaffSessions;
use Modules\Access\Application\Session\TrustedBrowsers;
use Modules\Access\Application\Staff\StaffMapper;
use Modules\Access\Domain\Exception\InvalidCode;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Exception\SignInRefused;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\PhoneCodePurpose;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Throwable;

/**
 * The right code signs them in. It also verifies the number it went to: a number an admin entered
 * and never verified (spec §1.4), or a Super Admin's new one after a reset (amendment 14), which
 * ends every trusted browser.
 */
final readonly class VerifyStaffSignInCodeHandler
{
    public const string PERMISSION = AccessPermissions::SESSION_SIGN_IN;

    public function __construct(
        private Authorizer $authorizer,
        private StaffUserRepository $staff,
        private StaffTokenRepository $tokens,
        private PhoneVerification $verification,
        private StaffSessions $sessions,
        private TrustedBrowsers $trusted,
        private PlatformApi $platform,
        private Connection $db,
        private ServedPhones $phones,
        private SecurityMessages $messages,
        private StaffSignInMethods $methods,
    ) {}

    /**
     * @return array{token: string, hours: int}|null the trust cookie to set, when the browser is trusted
     *
     * @throws InvalidCode|SignInRefused|PhoneAlreadyInUse
     */
    public function handle(VerifyStaffSignInCode $command): ?array
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $pending = $this->sessions->pendingSignIn() ?? throw new InvalidCode(requestNewCode: true);

        // A sign-in whose second step is the app sent no SMS code (amendment 91).
        if ($pending->byApp) {
            throw new InvalidCode(requestNewCode: true);
        }

        $started = false;

        // A wrong code is counted and committed before it is refused: a rolled-back count would let
        // a code be guessed without limit.
        try {
            $result = $this->db->transaction(function () use ($command, $pending, &$started): array|InvalidCode {
                $staff = $this->staff->byId($pending->staffId);

                if ($staff === null || $staff->status() !== StaffStatus::Active) {
                    throw new SignInRefused;
                }

                // A password changed or a phone given since: the sign-in starts again.
                if (! $pending->stillFor($staff, $this->phones)) {
                    throw new InvalidCode(requestNewCode: true);
                }

                $now = CarbonImmutable::now();
                $phone = $this->verification->check($staff->id(), PhoneCodePurpose::SignIn, $command->code, $now);

                if ($phone instanceof InvalidCode) {
                    return $phone;
                }

                // A saved phone SMS no longer reaches counts as none (amendment 90): the Super Admin's new
                // number replaces it.
                $current = $this->phones->reaches($staff->phone()) ? $staff->phone() : null;

                // A code counts only for the number the account has now, or for the number a Super Admin
                // with none entered: a code sent before an admin changed the number cannot bring the old
                // one back (review of step 3b). Returned, so the used code stays used.
                if ($current === null ? ! $pending->needsPhone : ! $current->equals($phone)) {
                    return new InvalidCode(requestNewCode: true);
                }

                if ($current === null || $staff->phoneVerifiedAt() === null) {
                    // A new number's country may have left the list since its code was sent (amendment 90).
                    $this->phones->require($phone);

                    if ($this->staff->phoneInUse($phone, $staff->id())) {
                        throw new PhoneAlreadyInUse;
                    }

                    $before = clone $staff;
                    $staff->verifyPhone($phone, $now);
                    $this->staff->update($staff);
                    $this->platform->recordAudit(StaffAudit::updated('access.staff_user.phone_verified', $before, $staff, $staff->pullChanges()));

                    if ($current === null) {
                        $this->tokens->forgetTrustedBrowsers($staff->id());

                        // The right password alone set this number: the address on the account hears of
                        // it, once the change commits (owner, 2026-10-30; amendment 90). A failed notice
                        // goes to the log and never breaks the sign-in (amendment 93).
                        $changed = StaffMapper::toDto($staff);
                        $this->db->afterCommit(fn () => rescue(fn () => $this->messages->staffPhoneChanged($changed)));
                    }
                }

                // Signed in first, so the entries name them as the actor, with their address. When the
                // app is their method and none is set up, the setup comes next (amendment 91).
                $this->sessions->start($staff->id(), $staff->sessionVersion(), $this->methods->mustSetUp($staff->id()));
                $started = true;
                $this->platform->recordAudit(StaffAudit::event('access.staff_user.signed_in', $staff, ['trusted_browser' => false]));

                if (! $command->trustBrowser) {
                    return ['trust' => null];
                }

                $trust = $this->trusted->trust($staff->id());
                $this->platform->recordAudit(StaffAudit::event('access.staff_user.browser_trusted', $staff));

                return ['trust' => $trust];
            }, 3);
        } catch (Throwable $failure) {
            // The session is started inside the transaction so the entries name them as the
            // actor; a transaction that then rolls back must leave no session behind (review of
            // step 7).
            if ($started) {
                $this->sessions->end();
            }

            throw $failure;
        }

        if ($result instanceof InvalidCode) {
            // An attempt that deadlocked after signing them in and then ran again may leave a
            // session behind a refusal: the code is refused, so nobody is signed in (review of
            // step 7).
            if ($started) {
                $this->sessions->end();
            }

            throw $result;
        }

        return $result['trust'];
    }
}
