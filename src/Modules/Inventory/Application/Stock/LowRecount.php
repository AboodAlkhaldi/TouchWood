<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

use Modules\Inventory\Domain\ValueObject\LowSet;

/**
 * **What a recount read** (inventory.md §1.7, amendment 1): the store's low sizes, as the list has them,
 * and the sizes of the kept set that left the list for another reason than their stock - archived, no
 * longer the store's, unknown - which leave silently.
 */
final readonly class LowRecount
{
    /**
     * @param  list<LowSize>  $sizes
     * @param  list<string>  $gone
     */
    public function __construct(
        public array $sizes,
        public array $gone,
    ) {}

    /** The low set: out when nothing is free, low otherwise. */
    public function set(): LowSet
    {
        $low = [];
        $out = [];

        foreach ($this->sizes as $size) {
            if ($size->free === 0) {
                $out[] = $size->variantId;
            } else {
                $low[] = $size->variantId;
            }
        }

        return new LowSet($low, $out);
    }
}
