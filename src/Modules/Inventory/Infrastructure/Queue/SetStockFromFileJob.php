<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Inventory\Application\Command\SetStockFromFile\SetStockFromFile;
use Modules\Inventory\Application\Command\SetStockFromFile\SetStockFromFileHandler;
use Shared\Domain\Error\DomainError;

/**
 * A store file's stock for one size (inventory.md §1.8), queued by `StockImportSection` once Catalog's
 * change commits. The system runs it for whoever switched the size on.
 */
final class SetStockFromFileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Three tries (platform.md §3: every queued job states its own); then the failed jobs screen. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly string $storeId,
        public readonly string $variantId,
        public readonly int $stock,
        public readonly string $reference,
    ) {}

    public function handle(SetStockFromFileHandler $handler): void
    {
        try {
            $handler->handle(new SetStockFromFile($this->storeId, $this->variantId, $this->stock, $this->reference));
        } catch (DomainError $refused) {
            // A refusal - a size archived meanwhile - is the same on every try: it goes to the failed
            // jobs screen at once, with its reason.
            $this->fail($refused);
        }
    }
}
