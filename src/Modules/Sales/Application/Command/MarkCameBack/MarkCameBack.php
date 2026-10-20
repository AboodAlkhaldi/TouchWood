<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\MarkCameBack;

/**
 * A shipped parcel that came back, marked by staff (sales.md §1.5), with the pieces that go back into stock — none for a parcel lost for good.
 */
final readonly class MarkCameBack
{
    /**
     * @param  list<array{variantId: string, quantity: int, gift?: bool}>  $putBack
     */
    public function __construct(
        public string $orderId,
        public array $putBack,
    ) {}
}
