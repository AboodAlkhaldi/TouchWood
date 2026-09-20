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
            $wasActive = $staff->status() === StaffStatus::Active;
            $staff->revokeSuperAdmin();

            // Revoked from the console, so the account goes — and its email and phone are free at
            // once (owner, 2026-09-20; amendment 45). Nobody is left without a role: a former
            // Super Admin who is to stay is invited again, like anyone else.
            $this->staff->update($staff);
            $this->platform->recordAudit(StaffAudit::updated('access.staff_user.super_admin_revoked', $before, $staff, $staff->pullChanges()));
            $this->tokens->deletePasswordReset($staff->id());
            $this->tokens->forgetTrustedBrowsers($staff->id());
            $this->cancellation->cancel($staff);

            if ($wasActive) {
                $this->events->dispatch(new StaffDisabled((string) Str::uuid(), $staff->id(), CarbonImmutable::now()));
            }

            $this->grants->refresh($staff->id());
        });
    }
}
