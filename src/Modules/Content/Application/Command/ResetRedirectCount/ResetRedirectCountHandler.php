<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ResetRedirectCount;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Redirects\RedirectAudit;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Repository\Redirects;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/** Resets a line's count (content.md §1.15: "The count can be reset line by line"). Audited with the count it had. */
final readonly class ResetRedirectCountHandler
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
    public function handle(ResetRedirectCount $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $this->db->transaction(function () use ($command): void {
            $line = $this->redirects->find($command->line, lock: true) ?? throw new ContentNotFound;

            if ($line->visits === 0 && $line->lastVisitAt === null) {
                return;
            }

            $this->redirects->resetCount($line->oldPath);
            $this->platform->recordAudit(ContentAudit::entry('content.redirect.count_reset', RedirectAudit::SUBJECT, RedirectAudit::subject($line->oldPath), null, AuditChanges::none()->changed('visits', $line->visits, 0)));
        }, 3);
    }
}
