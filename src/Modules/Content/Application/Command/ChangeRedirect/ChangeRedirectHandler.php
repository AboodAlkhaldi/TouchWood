<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ChangeRedirect;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Redirects\RedirectAudit;
use Modules\Content\Application\Redirects\RedirectRules;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\RedirectInvalid;
use Modules\Content\Domain\Model\Redirect;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Content\Domain\Repository\Redirects;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Changes a line (content.md §1.15, W11): both addresses checked as when added. A new address forgets what Check
 * Addresses found of the old one; a new old address must be free, and the line keeps its visits (the build's pick:
 * the same line, corrected).
 */
final readonly class ChangeRedirectHandler
{
    public const string PERMISSION = ContentPermissions::REDIRECT_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private RedirectRules $rules,
        private Redirects $redirects,
        private ContentLocks $locks,
        private ConnectionInterface $db,
        private PlatformApi $platform,
    ) {}

    /**
     * @return string the old address as kept now
     *
     * @throws RedirectInvalid|ContentNotFound|Unauthorized
     */
    public function handle(ChangeRedirect $command): string
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        [$old, $new] = $this->rules->line($command->oldPath, $command->newPath);

        $this->db->transaction(function () use ($command, $old, $new): void {
            $this->locks->lock(ContentLocks::ALL_STORES, ContentLocks::REDIRECTS);
            $line = $this->redirects->find($command->line, lock: true) ?? throw new ContentNotFound;

            if ($old !== $line->oldPath && $this->redirects->find($old) !== null) {
                throw new RedirectInvalid(RedirectInvalid::TAKEN);
            }

            $changed = $new === $line->newPath ? $line : $line->goingTo($new);
            $changed = $old === $line->oldPath ? $changed : new Redirect($old, $changed->newPath, $changed->visits, $changed->lastVisitAt, $changed->source, $changed->checkResult, $changed->checkFinalPath, $changed->checkedAt, $changed->createdBy);

            if ($changed == $line) {
                return;
            }

            $this->redirects->update($changed, $line->oldPath);
            $this->platform->recordAudit(ContentAudit::entry('content.redirect.changed', RedirectAudit::SUBJECT, RedirectAudit::subject($line->oldPath), null, RedirectAudit::diff($line, $changed)));
        });

        return $old;
    }
}
