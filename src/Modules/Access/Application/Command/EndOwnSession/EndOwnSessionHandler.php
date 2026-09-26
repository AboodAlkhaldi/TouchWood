<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\EndOwnSession;

use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Session\StaffSessionDirectory;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Ends one of their own sessions, by deleting its row.
 *
 * **The session version is deliberately left alone.** Raising it would end *every* session, which
 * is the other button; this one is for the machine somebody remembers leaving signed in. The
 * browser whose row this was is signed out on its next request, because a session id with no row
 * behind it is a new, empty session.
 *
 * It is not audited. A person ending their own browser's session is not a change to an account,
 * and an audit log that fills with it is one nobody reads (platform.md §1.5). Signing out
 * everywhere **is** audited, because that one is a security act.
 */
final readonly class EndOwnSessionHandler
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffSessionDirectory $sessions,
    ) {}

    public function handle(EndOwnSession $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        // Their id and the session id together: nobody ends anybody else's.
        $this->sessions->end($command->sessionId, $this->rules->currentStaffId());
    }
}
