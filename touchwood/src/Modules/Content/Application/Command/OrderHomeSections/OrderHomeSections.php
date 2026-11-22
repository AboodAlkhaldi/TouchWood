<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\OrderHomeSections;

/** The home page's sections dragged into a new order (content.md §1.4, W1): every one of the store's, each once. */
final readonly class OrderHomeSections
{
    /**
     * @param  list<string>  $sectionIds  top to bottom
     */
    public function __construct(
        public ?string $storeId,
        public array $sectionIds,
    ) {}
}
