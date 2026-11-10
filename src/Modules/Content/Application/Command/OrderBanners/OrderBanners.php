<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\OrderBanners;

/**
 * A placement's banners dragged into a new order (content.md §1.6, W3's Order column): the first live ones in it are
 * the ones that show. Every banner of the placement in the store, each once.
 */
final readonly class OrderBanners
{
    /**
     * @param  list<string>  $bannerIds  first to last
     */
    public function __construct(
        public ?string $storeId,
        public string $placement,
        public array $bannerIds,
    ) {}
}
