<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RevokeTrustedBrowser;

use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Untrusts one browser, or every one of them.
 *
 * **It signs nobody out.** A trusted browser is one allowed past the SMS code, not one kept signed
 * in; whoever is signed in there stays signed in until their session ends on its own. Ending the
 * sessions is the other button, and "sign out everywhere" does both because only both together
 * leave a borrowed machine with neither a way in nor a way past the code.
 *
 * Audited, unlike ending a single session: this one weakens or restores how hard it is to sign in.
 */
final readonly class RevokeTrustedBrowserHandler
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private StaffTokenRepository $tokens,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws StaffNotFound
     */
    public function handle(RevokeTrustedBrowser $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();
        $staff = $this->staff->byId($staffId) ?? throw new StaffNotFound($staffId);

        if ($command->browserId === null) {
            $this->tokens->forgetTrustedBrowsers($staffId);
        } else {
            // Their id as well: an id from a request may name somebody else's browser.
            $this->tokens->forgetTrustedBrowser($command->browserId, $staffId);
        }

        $this->platform->recordAudit(StaffAudit::event(
            $command->browserId === null
                ? 'access.staff_user.trusted_browsers_forgotten'
                : 'access.staff_user.trusted_browser_forgotten',
            $staff,
        ));
    }
}
