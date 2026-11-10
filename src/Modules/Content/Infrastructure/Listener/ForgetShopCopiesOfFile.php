<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Listener;

use Modules\Content\Application\ContentCache;
use Modules\Content\Infrastructure\Media\ContentMediaUsage;
use Modules\Platform\Public\Events\MediaVariantsReady;

/**
 * **A file's sizes becoming ready replaces the shop's cached copy** (content.md §1.5, §6), when Content uses the file:
 * the frame and the home page keep each image's addresses, which are empty until its sizes exist.
 */
final readonly class ForgetShopCopiesOfFile
{
    public function __construct(
        private ContentMediaUsage $usage,
        private ContentCache $cache,
    ) {}

    public function handle(MediaVariantsReady $event): void
    {
        if ($this->usage->usesOf($event->mediaId) !== []) {
            $this->cache->invalidate();
        }
    }
}
