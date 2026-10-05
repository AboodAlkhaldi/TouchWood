<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewImport;

/**
 * A store the file names for a product (§1.12): switched on there when accepted — when the panel has
 * that store —, with the price and stock as the file gave them (shown, kept from stage 5).
 */
final readonly class ImportStoreView
{
    public function __construct(
        public string $code,
        public bool $known,
        public ?string $price,
        public ?int $stock,
    ) {}
}
