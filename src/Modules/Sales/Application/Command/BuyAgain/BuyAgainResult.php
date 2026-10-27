<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\BuyAgain;

/** What Buy Again did: the cart's store, the lines added to it, and those left out with why. */
final readonly class BuyAgainResult
{
    /**
     * @param  list<BuyAgainSkipped>  $skipped
     */
    public function __construct(
        public string $storeId,
        public int $added,
        public array $skipped,
    ) {}
}
