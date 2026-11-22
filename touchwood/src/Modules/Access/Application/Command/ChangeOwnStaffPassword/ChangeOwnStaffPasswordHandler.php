<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChangeOwnStaffPassword;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\OwnPasswordCheck;
use Modules\Access\Application\Security\PasswordPolicy;
use Modules\Access\Application\Session\StaffSessions;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

final readonly class ChangeOwnStaffPasswordHandler
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private StaffTokenRepository $tokens,
        private PasswordPolicy $passwords,
        private OwnPasswordCheck $confirmation,
        private StaffSecuritySettings $settings,
        private StaffSessions $sessions,
        private GrantsReader $grants,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    public function handle(ChangeOwnStaffPassword $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();
        $staff = $this->staff->find($staffId) ?? throw new StaffNotFound($staffId);

        // The current password first, counted like a wrong one at sign-in.
        $this->confirmation->confirm($staff, $command->currentPassword, $command->ip);

        $passwordHash = $this->passwords->hashNew($command->newPassword, $this->settings->passwordMinLength());

        $version = $this->db->transaction(function () use ($staffId, $passwordHash): int {
            $staff = $this->staff->byId($staffId) ?? throw new StaffNotFound($staffId);
            $before = clone $staff;
            $staff->changePassword($passwordHash);
            $this->staff->update($staff);
            $this->tokens->forgetTrustedBrowsers($staff->id());
            $this->platform->recordAudit(StaffAudit::updated('access.staff_user.password_changed', $before, $staff, $staff->pullChanges()));
            $this->grants->refresh($staff->id());

            return $staff->sessionVersion();
        });

        $this->sessions->keep($version);
    }
}
