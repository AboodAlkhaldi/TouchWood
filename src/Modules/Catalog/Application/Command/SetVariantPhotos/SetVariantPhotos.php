<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetVariantPhotos;

/**
 * A variant's own photos, sent whole and in order (catalog.md §1.2): at most 10 public images, shown
 * when it is chosen, the product's gallery standing in when it has none.
 */
final readonly class SetVariantPhotos
{
    /**
     * @param  array<array-key, mixed>  $mediaIds  in order
     */
    public function __construct(
        public string $variantId,
        public array $mediaIds,
    ) {}
}
