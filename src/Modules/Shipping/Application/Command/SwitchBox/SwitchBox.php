<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\SwitchBox;

/**
 * A box on or off (shipping.md §4): off, it is in no suggestion.
 */
final readonly class SwitchBox
{
    public function __construct(
        public string $storeId,
        public string $boxId,
        public bool $on,
    ) {}
}
