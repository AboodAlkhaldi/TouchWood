<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewCart;

final readonly class ViewCart
{
    public function __construct(
        public string $storeId,
        /** The page's language, for the cards' names and links: 'ar' or 'en'. */
        public string $locale,
    ) {}
}
