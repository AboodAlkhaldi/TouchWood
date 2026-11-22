<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Media;

use Carbon\CarbonImmutable;
use Modules\Platform\Application\Audit\MediaAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Domain\Exception\InvalidMediaVariantsTransition;
use Modules\Platform\Domain\Model\Media;
use Modules\Platform\Domain\Repository\MediaRepository;
use Modules\Platform\Public\Dto\ModuleUploadDto;

/**
 * A person asks for an image's sizes again (Platform spec §4.1): by pressing Retry (RetryMediaVariants), or by
 * uploading the same public image again after its sizes failed (UploadMedia, owner 2026-11-10, P10 (a)). One
 * retry for both: Media::retryVariants decides whether it may, the row is written, the retry audited and the job
 * queued after the commit.
 *
 * The caller holds the media row's lock (MediaRepository::lockById) in its own transaction and has checked the
 * permission: whoever retries reads the row again under that lock, so two at once queue it once.
 */
final readonly class MediaVariantsRetry
{
    public function __construct(
        private MediaRepository $media,
        private MediaVariantsQueue $variants,
        private AuditLog $auditLog,
    ) {}

    /**
     * @param  Media  $locked  read by lockById in the caller's transaction
     * @param  bool  $byUpload  asked for by uploading the same image again, not by pressing Retry
     * @param  ModuleUploadDto|null  $forModule  that upload was a module's own, under its permission
     *
     * @throws InvalidMediaVariantsTransition when it may not be retried (Media::retryVariants)
     */
    public function retry(Media $locked, bool $byUpload = false, ?ModuleUploadDto $forModule = null): void
    {
        [$before, $queuedBefore] = [$locked->variantsStatus(), $locked->variantsQueuedAt()];

        $locked->retryVariants(CarbonImmutable::now());
        $this->media->update($locked);
        $this->auditLog->record(MediaAudit::variantsRetried($locked, $before, $queuedBefore, $byUpload, $forModule?->module, $forModule?->permission));
        $this->variants->generate($locked->id());
    }
}
