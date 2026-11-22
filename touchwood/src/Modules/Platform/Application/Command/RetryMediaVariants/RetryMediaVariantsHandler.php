<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RetryMediaVariants;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Media\MediaVariantsRetry;
use Modules\Platform\Application\Media\PrivateMedia;
use Modules\Platform\Domain\Exception\MediaNotFound;
use Modules\Platform\Domain\Repository\MediaRepository;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Staff queue variant generation again (Platform spec §4.1): for a FAILED image, or for one stuck
 * in PENDING longer than Media::STALE_PENDING_MINUTES because its job was lost. To anyone who may
 * not see private files, a private file does not exist here either (b2b.md amendment 8(a)).
 *
 * The retry itself (MediaVariantsRetry) is the one an upload of the same failed image makes too.
 */
final readonly class RetryMediaVariantsHandler
{
    public const string PERMISSION = PlatformPermissions::MEDIA_UPLOAD;

    public function __construct(
        private Authorizer $authorizer,
        private MediaRepository $media,
        private PrivateMedia $private,
        private MediaVariantsRetry $retry,
        private ConnectionInterface $db,
    ) {}

    public function handle(RetryMediaVariants $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $this->db->transaction(function () use ($command) {
            $media = $this->media->lockById($command->mediaId) ?? throw new MediaNotFound($command->mediaId);
            $this->private->reach($media, $command->mediaId);

            $this->retry->retry($media);
        });
    }
}
