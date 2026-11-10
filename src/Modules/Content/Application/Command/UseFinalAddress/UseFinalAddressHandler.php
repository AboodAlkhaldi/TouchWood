<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UseFinalAddress;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Redirects\RedirectAudit;
use Modules\Content\Application\Redirects\RedirectRules;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\RedirectInvalid;
use Modules\Content\Domain\Model\Redirect;
use Modules\Content\Domain\Repository\Redirects;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * A line Check Addresses marked as forwarding (a slug changed: a second hop) goes straight to the address it ends
 * on (content.md §1.15): that address checked as a new one, the mark forgotten until the next check.
 */
final readonly class UseFinalAddressHandler
{
    public const string PERMISSION = ContentPermissions::REDIRECT_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private RedirectRules $rules,
        private Redirects $redirects,
        private ConnectionInterface $db,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws RedirectInvalid|ContentNotFound|Unauthorized
     */
    public function handle(UseFinalAddress $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $this->db->transaction(function () use ($command): void {
            $line = $this->redirects->find($command->line, lock: true) ?? throw new ContentNotFound;

            if ($line->checkResult !== Redirect::FORWARDS || $line->checkFinalPath === null) {
                throw new RedirectInvalid(RedirectInvalid::NOT_FORWARDING);
            }

            $changed = $line->goingTo($this->rules->newPath($line->checkFinalPath));
            $this->redirects->update($changed);
            $this->platform->recordAudit(ContentAudit::entry('content.redirect.changed', RedirectAudit::SUBJECT, RedirectAudit::subject($line->oldPath), null, RedirectAudit::diff($line, $changed)));
        }, 3);
    }
}
