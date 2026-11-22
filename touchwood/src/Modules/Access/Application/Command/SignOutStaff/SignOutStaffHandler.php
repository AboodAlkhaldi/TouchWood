<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SignOutStaff;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Session\StaffSessions;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

final readonly class SignOutStaffHandler
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private StaffSessions $sessions,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    public function handle(SignOutStaff $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();

        // Recorded while still signed in, so the entry names them, with their address.
        $this->db->transaction(function () use ($staffId): void {
            $staff = $this->staff->find($staffId) ?? throw new StaffNotFound($staffId);
            $this->platform->recordAudit(StaffAudit::event('access.staff_user.signed_out', $staff));
        });

        $this->sessions->end();
    }
}
