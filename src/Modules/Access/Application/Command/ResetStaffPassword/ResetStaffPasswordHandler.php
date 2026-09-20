<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ResetStaffPassword;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\PasswordPolicy;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\Exception\InvalidOrExpiredLink;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

final readonly class ResetStaffPasswordHandler
{
    public const string PERMISSION = AccessPermissions::SESSION_RESET_PASSWORD;

    public function __construct(
        private Authorizer $authorizer,
        private StaffUserRepository $staff,
        private StaffTokenRepository $tokens,
        private PasswordPolicy $passwords,
        private StaffSecuritySettings $settings,
        private GrantsReader $grants,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    public function handle(ResetStaffPassword $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        // A dead link is refused before the slow part: the breach service and the hasher.
        $early = $this->tokens->passwordResetByToken(SecretTokens::hash($command->token));

        if ($early === null || $early->isExpired(CarbonImmutable::now())) {
            throw new InvalidOrExpiredLink;
        }

        $passwordHash = $this->passwords->hashNew($command->password, $this->settings->passwordMinLength());

        $this->db->transaction(function () use ($command, $passwordHash): void {
            $reset = $this->tokens->passwordResetByToken(SecretTokens::hash($command->token));

            if ($reset === null || $reset->isExpired(CarbonImmutable::now())) {
                throw new InvalidOrExpiredLink;
            }

            $staff = $this->staff->byId($reset->staffId);

            if ($staff === null || $staff->status() !== StaffStatus::Active) {
                throw new InvalidOrExpiredLink;
            }

            $before = clone $staff;
            $staff->changePassword($passwordHash);
            $this->staff->update($staff);
            $this->tokens->deletePasswordReset($staff->id());
            $this->tokens->forgetTrustedBrowsers($staff->id());
            $this->platform->recordAudit(StaffAudit::updated('access.staff_user.password_reset', $before, $staff, $staff->pullChanges()));
            // The new session version reaches every open session at once: they all end.
            $this->grants->refresh($staff->id());
        });
    }
}
