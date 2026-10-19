<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query\ListFavourites;

/** A store's "Favourited products", a page at a time: `after` is the last page's `next`. */
final readonly class ListFavourites
{
    public function __construct(
        public string $storeId,
        public string $locale,
        public ?string $after = null,
    ) {}
}
