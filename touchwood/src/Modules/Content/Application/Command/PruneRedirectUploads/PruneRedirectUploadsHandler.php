<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\PruneRedirectUploads;

use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Repository\RedirectUploads;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Removes checked redirect sheets older than 24 hours (content.md §5, §9.3 #31: "upload page dropped after 24
 * hours"), so a sheet nobody applied does not wait for the next upload to go. They are read as gone from that age
 * anyway. The system's, under the redirects' own reserved job (`content.redirects.check`; the build's pick).
 */
final readonly class PruneRedirectUploadsHandler
{
    public const string PERMISSION = ContentPermissions::REDIRECTS_CHECK;

    public function __construct(
        private Authorizer $authorizer,
        private RedirectUploads $uploads,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(PruneRedirectUploads $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $this->uploads->pruneBefore(Moments::now()->modify('-24 hours'));
    }
}
