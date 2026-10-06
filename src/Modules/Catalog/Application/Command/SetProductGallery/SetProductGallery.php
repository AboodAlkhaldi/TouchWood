<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetProductGallery;

/**
 * A product's gallery, sent whole and in order (catalog.md §1.1): at most 20 public images.
 */
final readonly class SetProductGallery
{
    /**
     * @param  array<array-key, mixed>  $mediaIds  in order
     */
    public function __construct(
        public string $productId,
        public array $mediaIds,
    ) {}
}
