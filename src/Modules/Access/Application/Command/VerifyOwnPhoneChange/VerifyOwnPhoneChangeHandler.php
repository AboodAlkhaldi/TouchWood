<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\VerifyOwnPhoneChange;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\PhoneVerification;
use Modules\Access\Domain\Exception\InvalidCode;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\PhoneCodePurpose;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * A changed phone ends every trusted browser (spec §1.8).
 */
final readonly class VerifyOwnPhoneChangeHandler
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private StaffTokenRepository $tokens,
        private PhoneVerification $verification,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    public function handle(VerifyOwnPhoneChange $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();

        // A wrong code is counted and committed before the error is thrown (PhoneVerification).
        $failure = $this->db->transaction(function () use ($command, $staffId): ?InvalidCode {
            $now = CarbonImmutable::now();
            $staff = $this->staff->byId($staffId) ?? throw new StaffNotFound($staffId);
            $phone = $this->verification->check($staff->id(), PhoneCodePurpose::Change, $command->code, $now);

            if ($phone instanceof InvalidCode) {
                return $phone;
            }

            if ($this->staff->phoneInUse($phone, $staff->id())) {
                throw new PhoneAlreadyInUse;
            }

            $before = clone $staff;
            $staff->verifyPhone($phone, $now);
            $this->staff->update($staff);
            $this->tokens->forgetTrustedBrowsers($staff->id());
            $this->tokens->deletePhoneCode($staff->id(), PhoneCodePurpose::SignIn);
            $this->platform->recordAudit(StaffAudit::updated('access.staff_user.phone_changed', $before, $staff, $staff->pullChanges()));

            return null;
        });

        if ($failure !== null) {
            throw $failure;
        }
    }
}
