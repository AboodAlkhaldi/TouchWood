<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\AddRedirect;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Redirects\RedirectAudit;
use Modules\Content\Application\Redirects\RedirectRules;
use Modules\Content\Domain\Exception\RedirectInvalid;
use Modules\Content\Domain\Model\Redirect;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Content\Domain\Repository\Redirects;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Adds a line to the redirects by hand (content.md §1.15, W11): Super Admins, store-free. Both addresses checked
 * (`RedirectRules`); an old address another line holds is refused (`taken`) under the redirects' lock, so two
 * at once can't both add it.
 */
final readonly class AddRedirectHandler
{
    public const string PERMISSION = ContentPermissions::REDIRECT_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private RedirectRules $rules,
        private Redirects $redirects,
        private ContentLocks $locks,
        private ContentAccess $access,
        private ConnectionInterface $db,
        private PlatformApi $platform,
    ) {}

    /**
     * @return string the old address as kept
     *
     * @throws RedirectInvalid|Unauthorized
     */
    public function handle(AddRedirect $command): string
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        [$old, $new] = $this->rules->line($command->oldPath, $command->newPath);
        $by = $this->access->staffId();

        $this->db->transaction(function () use ($old, $new, $by): void {
            $this->locks->lock(ContentLocks::ALL_STORES, ContentLocks::REDIRECTS);

            if ($this->redirects->find($old) !== null) {
                throw new RedirectInvalid(RedirectInvalid::TAKEN);
            }

            $line = new Redirect($old, $new, 0, null, Redirect::HAND, null, null, null, $by);
            $this->redirects->add($line);
            $this->platform->recordAudit(ContentAudit::entry('content.redirect.added', RedirectAudit::SUBJECT, RedirectAudit::subject($old), null, RedirectAudit::diff(null, $line)));
        });

        return $old;
    }
}
