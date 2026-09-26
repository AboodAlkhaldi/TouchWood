<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\MySessions;

use Carbon\CarbonImmutable;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Session\StaffSessionDirectory;
use Modules\Access\Domain\Model\TrustedBrowser;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Where a staff member is signed in, and which browsers they have trusted (owner, 2026-09-26).
 *
 * It answers about the person asking and nobody else: the id comes from who is signed in, so there
 * is no id here to hand it somebody else's. **Their own only** — an admin never reads another
 * person's sessions from this screen, which was the owner's own limit on the feature.
 *
 * The two lists are different things and are shown as two:
 *
 *   - a **session** is a browser signed in now, which ending logs out;
 *   - a **trusted browser** is a browser allowed to sign in with the password alone, skipping the
 *     SMS code, for as long as the trust lasts (§1.8). Ending a session does not untrust anything,
 *     and untrusting does not sign anybody out. Only doing both leaves a borrowed machine with
 *     neither a way in nor a way past the code.
 */
final readonly class MySessionsForStaff
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffSessionDirectory $sessions,
        private StaffTokenRepository $tokens,
    ) {}

    public function forCurrentStaff(?string $currentSessionId): MySessionsDto
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();

        $sessions = array_map(static fn (array $row): StaffSessionDto => new StaffSessionDto(
            id: $row['id'],
            ipAddress: $row['ip_address'],
            userAgent: $row['user_agent'],
            lastActivity: CarbonImmutable::createFromTimestamp($row['last_activity'])->toIso8601String(),
            // Named rather than guessed: the caller knows which session it is answering.
            isCurrent: $currentSessionId !== null && $row['id'] === $currentSessionId,
        ), $this->sessions->forStaff($staffId));

        $trusted = array_map(static fn (TrustedBrowser $browser): TrustedBrowserDto => new TrustedBrowserDto(
            id: $browser->id,
            expiresAt: CarbonImmutable::parse($browser->expiresAt)->toIso8601String(),
        ), $this->tokens->trustedBrowsersFor($staffId));

        return new MySessionsDto($sessions, $trusted);
    }
}
