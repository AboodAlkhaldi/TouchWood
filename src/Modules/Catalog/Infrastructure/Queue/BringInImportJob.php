<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Catalog\Application\Command\BringInImportProducts\BringInImportProducts;
use Modules\Catalog\Application\Command\BringInImportProducts\BringInImportProductsHandler;

/**
 * Bringing an import's products in, queued by `BringInImport` (catalog.md §1.12, page part 3). It acts
 * as the system on behalf of the Super Admin who asked (Platform's queued actor).
 */
final class BringInImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * One try (platform.md §3): a failure is recorded on the import's page with its reason, nothing
     * kept, and the Super Admin starts it again once it is mended.
     */
    public int $tries = 1;

    /** A zip of photos takes minutes. */
    public int $timeout = 3600;

    public function __construct(
        public readonly string $importId,
    ) {}

    public function handle(BringInImportProductsHandler $handler): void
    {
        $handler->handle(new BringInImportProducts($this->importId));
    }
}
