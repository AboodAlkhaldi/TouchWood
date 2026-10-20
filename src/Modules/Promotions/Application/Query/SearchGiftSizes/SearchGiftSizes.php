<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\SearchGiftSizes;

/** The sizes of the store matching what was typed — by product name or size code — for a gift level. */
final readonly class SearchGiftSizes
{
    public function __construct(
        public string $storeId,
        public string $text,
    ) {}
}
