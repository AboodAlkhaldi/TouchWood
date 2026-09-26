<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SignOutEverywhere;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Session\StaffSessionDirectory;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Every session of theirs ended at once, **including the one that pressed the button** (owner,
 * 2026-09-26).
 *
 * The case it is for: an admin lends their account to a staff member for a job, and wants it back.
 * So it does three things, and needs all three to be worth pressing:
 *
 *   1. **Raises the session version**, which is what ends a session that this process cannot reach
 *      — another machine's row is rejected on its next request, exactly as a password change does.
 *   2. **Deletes the session rows**, so the sessions are gone now rather than whenever those
 *      browsers next ask. Somebody watching the list sees them go.
 *   3. **Forgets every trusted browser**, so the borrowed machine must ask for an SMS code again —
 *      which is the whole point: without it, the person who was lent the account still walks past
 *      the code (§1.8).
 *
 * The password is deliberately **not** asked for. They are already signed in, this takes nothing
 * away that signing in again does not restore, and a screen that asks for a password before
 * letting somebody secure their account is a screen people give up on.
 */
final readonly class SignOutEverywhereHandler
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private StaffTokenRepository $tokens,
        private StaffSessionDirectory $sessions,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /**
     * @throws StaffNotFound
     */
    public function handle(SignOutEverywhere $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();

        $this->db->transaction(function () use ($staffId): void {
            $staff = $this->staff->byId($staffId) ?? throw new StaffNotFound($staffId);

            // The same mechanism a password change uses: every session holding the old number is
            // refused on its next request, wherever it is.
            $staff->endEverySession();
            $this->staff->update($staff);
            $staff->pullChanges();

            $this->tokens->forgetTrustedBrowsers($staffId);
            $this->sessions->endAll($staffId);

            $this->platform->recordAudit(StaffAudit::event('access.staff_user.signed_out_everywhere', $staff));
        }, 3);
    }
}
