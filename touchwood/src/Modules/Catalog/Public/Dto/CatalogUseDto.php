<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

use Modules\Catalog\Public\Enums\CatalogItem;

/**
 * One place another module uses a Catalog item (amendment 19): which module, what of its own holds
 * it — "coupon", "gift_level" — and that thing's id, for a screen to name it through its module.
 */
final readonly class CatalogUseDto
{
    public function __construct(
        public string $module,
        public string $kind,
        public string $id,
        public CatalogItem $item,
        public string $itemId,
    ) {}
}
