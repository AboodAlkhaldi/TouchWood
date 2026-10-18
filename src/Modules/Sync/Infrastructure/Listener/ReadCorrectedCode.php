<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Listener;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Events\VariantCodeCorrected;
use Modules\Sync\Domain\Repository\Connections;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\ValueObject\ReadKind;
use Shared\Domain\ValueObject\StoreId;
use Throwable;

/**
 * Staff corrected a size's code (catalog.md §6.1): in every wired store, the size is matched again at
 * once - a one-code read of its new code (sync.md §1.3, §6). After Catalog's change commits (the event
 * waits for it); each read is a job of its own.
 */
final readonly class ReadCorrectedCode
{
    public function __construct(
        private CatalogApi $catalog,
        private Connections $connections,
        private Reads $reads,
    ) {}

    public function handle(VariantCodeCorrected $event): void
    {
        $wired = $this->connections->wiredStores();

        if ($wired === []) {
            return;
        }

        try {
            $code = $this->catalog->variant($event->variantId)?->code;
        } catch (Throwable $error) {
            report($error);

            return;
        }

        if ($code === null) {
            return;
        }

        foreach ($wired as $store) {
            // The correction is saved already: a read that cannot be queued is reported, not shown to
            // staff as their correction failing. The nightly read matches the size all the same.
            try {
                $this->reads->queue(StoreId::fromString($store), ReadKind::Code, [$code]);
            } catch (Throwable $error) {
                report($error);
            }
        }
    }
}
