<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Listener;

use Modules\Catalog\Application\Listing\CardPhotoReady;
use Modules\Platform\Public\Events\MediaVariantsReady;

/**
 * A photo whose sizes became ready may be a product card's photo now (catalog.md §6.2).
 */
final readonly class RefreshCardPhotos
{
    public function __construct(
        private CardPhotoReady $cards,
    ) {}

    public function handle(MediaVariantsReady $event): void
    {
        $this->cards->handle($event->mediaId);
    }
}
