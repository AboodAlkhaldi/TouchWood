<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RevokeSuperAdmin;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Staff\StaffCancellation;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\LastSuperAdmin;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Access\Public\Events\StaffDisabled;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * A former Super Admin has no role, and nobody works without one: the account is disabled, with
 * every link and code it had, until an admin enables it together with a role. One who never
 * accepted is cancelled instead, and freed (owner's decisions, 2026-09-19).
 */
final readonly class RevokeSuperAdminHandler
{
    public const string PERMISSION = AccessPermissions::SUPER_ADMIN_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private StaffTokenRepository $tokens,
        private GrantsReader $grants,
        private StaffCancellation $cancellation,
        private PlatformApi $platform,
        private Dispatcher $events,
        private Connection $db,
    ) {}

    public function handle(RevokeSuperAdmin $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $this->rules->requireConsole(self::PERMISSION);
        $email = EmailAddress::of($command->email);

        $this->db->transaction(function () use ($email): void {
            // Locked first, so two revokes at once cannot each leave the other as "the last one".
            $active = $this->staff->activeSuperAdminIds();
            $staff = $this->staff->byEmail($email) ?? throw new StaffNotFound($email->value);

            if (! $staff->isSuperAdmin()) {
                throw new InvalidAccessAttribute('email', 'not a Super Admin');
            }

            if ($staff->status() === StaffStatus::Active && count($active) <= 1) {
                throw new LastSuperAdmin;
            }

            $before = clone $staff;
            $staff->revokeSuperAdmin();

            // Never accepted: cancelled and freed, like any invitation withdrawn (amendment 30).
            if ($staff->status() === StaffStatus::Invited) {
                $this->staff->update($staff);
                $this->platform->recordAudit(StaffAudit::updated('access.staff_user.super_admin_revoked', $before, $staff, $staff->pullChanges()));
                $this->cancellation->cancel($staff);

                return;
            }

            if ($staff->status() === StaffStatus::Active) {
                $staff->disable();
                $this->events->dispatch(new StaffDisabled((string) Str::uuid(), $staff->id(), CarbonImmutable::now()));
            }

            $this->staff->update($staff);
            $this->tokens->deleteInvitation($staff->id());
            $this->tokens->deletePhoneCode($staff->id());
            $this->tokens->deleteEmailChange($staff->id());
            $this->tokens->deletePasswordReset($staff->id());
            $this->tokens->forgetTrustedBrowsers($staff->id());
            $this->platform->recordAudit(StaffAudit::updated('access.staff_user.super_admin_revoked', $before, $staff, $staff->pullChanges()));
            $this->grants->refresh($staff->id());
        });
    }
}
