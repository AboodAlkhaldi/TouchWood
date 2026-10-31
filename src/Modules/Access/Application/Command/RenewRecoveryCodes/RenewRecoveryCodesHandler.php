<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RenewRecoveryCodes;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\Codes;
use Modules\Access\Application\Security\OwnPasswordCheck;
use Modules\Access\Application\Security\RecoveryCodes;
use Modules\Access\Domain\Exception\AccountLocked;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\NoAuthenticator;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffAuthenticatorRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * "New codes" replaces all of them (amendment 91, decision 4), after the current password (decision 6);
 * the new ones are returned to be shown once. Audited.
 */
final readonly class RenewRecoveryCodesHandler
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private OwnPasswordCheck $confirmation,
        private StaffAuthenticatorRepository $authenticators,
        private Codes $hashes,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /**
     * @return list<string>
     *
     * @throws InvalidAccessAttribute|AccountLocked|NoAuthenticator
     */
    public function handle(RenewRecoveryCodes $command): array
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();

        $this->confirmation->confirm(
            $this->staff->find($staffId) ?? throw new StaffNotFound($staffId),
            $command->currentPassword,
            $command->ip,
        );

        return $this->db->transaction(function () use ($staffId): array {
            $staff = $this->staff->byId($staffId) ?? throw new StaffNotFound($staffId);

            if (! $this->authenticators->hasConfirmedApp($staffId, lock: true)) {
                throw new NoAuthenticator;
            }

            $codes = RecoveryCodes::generate();
            $this->authenticators->replaceRecoveryCodes($staffId, array_map(fn (string $code): string => $this->hashes->hash($staffId, RecoveryCodes::normalize($code)), $codes), CarbonImmutable::now());
            $this->platform->recordAudit(StaffAudit::event('access.staff_user.recovery_codes_renewed', $staff));

            return $codes;
        }, 3);
    }
}
