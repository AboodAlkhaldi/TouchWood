<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewStoreFill;

/**
 * A store file's page (catalog.md §1.3; amendment 6(g)): its items as the file gave them, each with
 * what the admin did and — still open — where it stands now. Prices and stock are shown, and kept
 * from stage 5 (`$pricesKept`).
 */
final readonly class StoreFillView
{
    /**
     * @param  list<StoreFillItemView>  $items
     */
    public function __construct(
        public string $id,
        public string $storeId,
        public string $fileName,
        public ?string $uploadedBy,
        public string $uploadedAt,
        public array $items,
        public bool $pricesKept,
    ) {}
}
