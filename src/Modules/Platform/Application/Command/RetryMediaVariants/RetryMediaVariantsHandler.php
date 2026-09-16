<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RetryMediaVariants;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Audit\MediaAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Media\MediaVariantsQueue;
use Modules\Platform\Domain\Exception\MediaNotFound;
use Modules\Platform\Domain\Repository\MediaRepository;
use Shared\Application\Authorizer;

/**
 * Staff queue variant generation again (Platform spec §4.1): for a FAILED image, or for one stuck
 * in PENDING longer than Media::STALE_PENDING_MINUTES because its job was lost.
 */
final readonly class RetryMediaVariantsHandler
{
    public const string PERMISSION = 'platform.media.upload';

    public function __construct(
        private Authorizer $authorizer,
        private MediaRepository $media,
        private MediaVariantsQueue $variants,
        private AuditLog $auditLog,
        private ConnectionInterface $db,
    ) {}

    public function handle(RetryMediaVariants $command): void
    {
        $this->authorizer->authorize(self::PERMISSION);

        $this->db->transaction(function () use ($command) {
            $media = $this->media->lockById($command->mediaId) ?? throw new MediaNotFound($command->mediaId);
            $before = $media->variantsStatus();

            $media->retryVariants(CarbonImmutable::now());
            $this->media->update($media);
            $this->auditLog->record(MediaAudit::variantsRetried($media, $before));
            $this->variants->generate($media->id());
        });
    }
}
