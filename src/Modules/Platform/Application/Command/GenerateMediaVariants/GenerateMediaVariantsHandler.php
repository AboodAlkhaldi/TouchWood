<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\GenerateMediaVariants;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Platform\Application\Media\ImageVariantGenerator;
use Modules\Platform\Application\Media\MediaStorage;
use Modules\Platform\Domain\Repository\MediaRepository;
use Modules\Platform\Public\Enums\MediaVariantsStatus;
use Modules\Platform\Public\Events\MediaVariantsReady;
use Shared\Application\Authorizer;

/**
 * Generates every variant of a public image, once, in the background — never at request time
 * (Platform spec §1.4). Runs as the system; safe to run twice.
 */
final readonly class GenerateMediaVariantsHandler
{
    /** Reserved: only the system generates variants. */
    public const string PERMISSION = 'platform.media.variants.generate';

    public function __construct(
        private Authorizer $authorizer,
        private MediaRepository $media,
        private MediaStorage $storage,
        private ImageVariantGenerator $generator,
        private ConnectionInterface $db,
        private Dispatcher $events,
    ) {}

    public function handle(GenerateMediaVariants $command): void
    {
        $this->authorizer->authorize(self::PERMISSION);

        $media = $this->media->byId($command->mediaId);

        // Deleted meanwhile, already done, or already failed: nothing to do.
        if ($media === null || $media->variantsStatus() !== MediaVariantsStatus::Pending) {
            return;
        }

        // The slow part runs outside any transaction, so no row stays locked while images encode.
        foreach ($this->generator->variants($this->storage->readOriginal($media)) as [$size, $format, $contents]) {
            $this->storage->putVariant($media, $size, $format, $contents);
        }

        $deletedMeanwhile = $this->db->transaction(function () use ($command): bool {
            $current = $this->media->lockById($command->mediaId);

            if ($current === null) {
                return true;
            }

            // Another run finished first: its variants are the same files.
            if ($current->variantsStatus() !== MediaVariantsStatus::Pending) {
                return false;
            }

            $current->markVariantsReady(CarbonImmutable::now());
            $this->media->update($current);
            $this->events->dispatch(new MediaVariantsReady((string) Str::uuid(), $current->id(), CarbonImmutable::now()));

            return false;
        });

        // The media was deleted while its variants were being written, after the delete had
        // already removed its files: remove what this run wrote, or it stays public for good.
        if ($deletedMeanwhile) {
            $this->storage->deleteNow($media);
        }
    }

    /**
     * Called once the queue has run out of attempts: PENDING → FAILED.
     */
    public function fail(GenerateMediaVariants $command): void
    {
        $this->authorizer->authorize(self::PERMISSION);

        $this->db->transaction(function () use ($command) {
            $media = $this->media->lockById($command->mediaId);

            if ($media === null || $media->variantsStatus() !== MediaVariantsStatus::Pending) {
                return;
            }

            $media->markVariantsFailed();
            $this->media->update($media);
        });
    }
}
