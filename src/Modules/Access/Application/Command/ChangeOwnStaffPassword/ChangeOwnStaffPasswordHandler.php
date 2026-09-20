<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChangeOwnStaffPassword;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\Codes;
use Modules\Access\Application\Security\PasswordPolicy;
use Modules\Access\Application\Security\SignInLimits;
use Modules\Access\Application\Session\StaffSessions;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
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
        private SignInLimits $limits,
        private Codes $codes,
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
        $email = $staff->email()->value;

        // Counted like a wrong password at sign-in: a stolen session cannot guess the current
        // password without limit (review of step 3b).
        $this->limits->begin($email, $command->ip);

        if (! $this->passwords->matches($command->currentPassword, $staff->passwordHash())) {
            $locked = $this->limits->failed($email, $command->ip);

            if ($locked['account'] || $locked['address']) {
                $this->db->transaction(function () use ($locked, $staff, $command): void {
                    if ($locked['account']) {
                        $this->platform->recordAudit(StaffAudit::event('access.staff_user.locked_out', $staff));
                    }

                    if ($locked['address']) {
                        $this->platform->recordAudit(StaffAudit::addressLocked($this->codes->hash('sign-in-address', $command->ip)));
                    }
                });
            }

            throw new InvalidAccessAttribute('current_password', 'not the current password');
        }

        $this->limits->succeeded($email, $command->ip);

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
