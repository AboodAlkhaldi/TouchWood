<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

use Modules\Catalog\Public\Dto\VariantBriefDto;

/**
 * **A store's low sizes as the reader found them** (inventory.md §1.7): the sizes, the fewest left first
 * then by size id; each in brief as Catalog read it to leave the archived ones off - kept, so the list
 * orders by product name and place without reading them again; and the store's wiring and default
 * threshold, which decided them.
 */
final readonly class LowRead
{
    /**
     * @param  list<LowSize>  $sizes
     * @param  array<string, VariantBriefDto>  $briefs  by size id
     */
    public function __construct(
        public array $sizes,
        public array $briefs,
        public bool $wired,
        public int $defaultThreshold,
    ) {}
}
