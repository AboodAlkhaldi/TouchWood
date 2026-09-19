<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RequestStaffPasswordReset;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Connection;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Application\Staff\StaffLinks;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Enums\StaffStatus;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Only an active account gets a link (30 minutes, amendment 31); a new one replaces the last. At most
 * a few an hour to one account, so nobody can flood an inbox. Every other case ends quietly, the same
 * way.
 */
final readonly class RequestStaffPasswordResetHandler
{
    public const string PERMISSION = AccessPermissions::SESSION_RESET_PASSWORD;

    public function __construct(
        private Authorizer $authorizer,
        private StaffUserRepository $staff,
        private StaffTokenRepository $tokens,
        private StaffSecuritySettings $settings,
        private SecurityMessages $messages,
        private StaffLinks $links,
        private RateLimiter $limiter,
        private Connection $db,
    ) {}

    public function handle(RequestStaffPasswordReset $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        try {
            $email = EmailAddress::of($command->email);
        } catch (InvalidAccessAttribute) {
            return;
        }

        $this->db->transaction(function () use ($email): void {
            $staff = $this->staff->byEmail($email);

            if ($staff === null || $staff->status() !== StaffStatus::Active) {
                return;
            }

            $hourly = 'access:staff-password-reset:'.$staff->id();

            if ($this->limiter->tooManyAttempts($hourly, $this->settings->passwordResetsPerHour())) {
                return;
            }

            $this->limiter->hit($hourly, 3600);

            $link = SecretTokens::issue();
            $this->tokens->putPasswordReset($staff->id(), $link['hash'], CarbonImmutable::now()->addMinutes($this->settings->passwordResetMinutes()));

            // Sent once the transaction commits, never queued: a job would keep the link in the jobs table.
            $this->db->afterCommit(fn () => $this->messages->passwordReset($staff->email()->value, $staff->language()->value, $this->links->passwordReset($link['token'])));
        });
    }
}
