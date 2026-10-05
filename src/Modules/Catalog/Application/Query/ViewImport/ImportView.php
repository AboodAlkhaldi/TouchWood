<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewImport;

/**
 * A products file's page (catalog.md §1.12): where it stands — and why it failed —, the names the
 * catalog lacks, and its products with their codes, decisions, states and what each still lacks.
 * Prices and stock are shown as the file gave them; until Pricing and Inventory exist (stage 5)
 * they are not kept, which the page says once (`$pricesKept`).
 */
final readonly class ImportView
{
    /**
     * @param  list<ImportNameView>  $names
     * @param  list<ImportProductView>  $products
     */
    public function __construct(
        public string $id,
        public string $fileName,
        public string $state,
        public ?string $failure,
        public bool $withPhotos,
        public ?string $uploadedBy,
        public string $uploadedAt,
        public array $names,
        public array $products,
        public bool $pricesKept,
    ) {}
}
