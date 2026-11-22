<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AttachLabels;

/**
 * The labels one store shows on a product, sent whole.
 */
final readonly class AttachLabels
{
    /**
     * @param  array<array-key, mixed>  $labelIds
     */
    public function __construct(
        public string $storeId,
        public string $productId,
        public array $labelIds,
    ) {}
}
