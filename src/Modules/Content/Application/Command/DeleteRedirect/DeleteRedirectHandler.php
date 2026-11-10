<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeleteRedirect;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Redirects\RedirectAudit;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Repository\Redirects;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/** Deletes a line (content.md §1.15, W11): its old address answers not found from then on. Audited. */
final readonly class DeleteRedirectHandler
{
    public const string PERMISSION = ContentPermissions::REDIRECT_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private Redirects $redirects,
        private ConnectionInterface $db,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws ContentNotFound|Unauthorized
     */
    public function handle(DeleteRedirect $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $this->db->transaction(function () use ($command): void {
            $line = $this->redirects->find($command->line, lock: true) ?? throw new ContentNotFound;
            $this->redirects->delete($line->oldPath);
            $this->platform->recordAudit(ContentAudit::entry('content.redirect.deleted', RedirectAudit::SUBJECT, RedirectAudit::subject($line->oldPath), null, RedirectAudit::diff($line, null)));
        });
    }
}
