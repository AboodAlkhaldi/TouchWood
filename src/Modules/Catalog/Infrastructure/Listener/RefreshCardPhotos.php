<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Catalog\Application\Listing\CardPhotoReady;
use Modules\Platform\Public\Events\MediaVariantsReady;

/**
 * A photo whose sizes became ready may be a product card's photo now (catalog.md §6.2).
 *
 * **From the queue**, retried: the photo's own job sends the event once — a retry of that job finds
 * the photo ready and sends nothing — so a refresh that fails here (a lock that waits too long, the
 * database away) is tried again instead of leaving the card behind until a repair. On `sync`, as the
 * tests run, it runs at once. Done once however often it runs: the rows it writes are the same.
 */
final class RefreshCardPhotos implements ShouldQueue
{
    /** Three attempts over about a minute. */
    public int $tries = 3;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60];

    public function __construct(
        private readonly CardPhotoReady $cards,
    ) {}

    public function handle(MediaVariantsReady $event): void
    {
        $this->cards->handle($event->mediaId);
    }
}
