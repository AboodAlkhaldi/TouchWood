<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Pricing\Application\Command\SetPriceFromFile\SetPriceFromFile;
use Modules\Pricing\Application\Command\SetPriceFromFile\SetPriceFromFileHandler;
use Shared\Domain\Error\DomainError;

/**
 * A store file's price for the sizes one item switched on (pricing.md §1.8), queued by
 * `PriceImportSection` once Catalog's change commits. The system runs it for whoever switched it on.
 */
final class SetPriceFromFileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Three tries (platform.md §3: every queued job states its own); then the failed jobs screen. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  list<string>  $variantIds
     */
    public function __construct(
        public readonly string $storeId,
        public readonly array $variantIds,
        public readonly string $price,
    ) {}

    public function handle(SetPriceFromFileHandler $handler): void
    {
        try {
            $handler->handle(new SetPriceFromFile($this->storeId, $this->variantIds, $this->price));
        } catch (DomainError $refused) {
            // A refusal - the price held back by the ceiling, a size archived meanwhile - is the same
            // on every try: it goes to the failed jobs screen at once, with its reason.
            $this->fail($refused);
        }
    }
}
