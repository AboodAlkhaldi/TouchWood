<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RequeueStuckMediaVariants;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Media\MediaVariantsQueue;
use Modules\Platform\Domain\Model\Media;
use Modules\Platform\Domain\Repository\MediaRepository;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The scheduled sweep (owner's decision, 2026-09-16): an image still PENDING
 * Media::STALE_PENDING_MINUTES after it was queued has lost its job — the queue was down, or the
 * worker died — so it is queued again. Queuing twice is harmless: a finished job does nothing.
 */
final readonly class RequeueStuckMediaVariantsHandler
{
    /** Reserved: only the system sweeps. */
    public const string PERMISSION = PlatformPermissions::MEDIA_VARIANTS_GENERATE;

    public function __construct(
        private Authorizer $authorizer,
        private MediaRepository $media,
        private MediaVariantsQueue $variants,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return int how many images were queued again
     */
    public function handle(RequeueStuckMediaVariants $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $now = CarbonImmutable::now();
        $requeued = 0;

        foreach ($this->media->stalePendingIds($now->subMinutes(Media::STALE_PENDING_MINUTES), $command->limit) as $id) {
            $requeued += (int) $this->db->transaction(function () use ($id, $now): bool {
                $media = $this->media->lockById($id);

                // Finished or deleted since the list was read.
                if ($media === null || ! $media->isStalePending($now)) {
                    return false;
                }

                $media->retryVariants($now);
                $this->media->update($media);
                $this->variants->generate($media->id());

                return true;
            });
        }

        return $requeued;
    }
}
