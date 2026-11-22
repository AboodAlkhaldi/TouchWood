<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Products;

use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\MediaVariantsStatus;

/**
 * Which of a product's gallery photos have their sizes ready (catalog.md §1.1): a ready product needs
 * at least one. Asked of Platform photo by photo — a gallery holds at most 20.
 */
final readonly class ReadyPhotos
{
    public function __construct(
        private ProductRepository $products,
        private PlatformApi $platform,
    ) {}

    /**
     * @return list<string> the media ids, in the gallery's order
     */
    public function of(string $productId): array
    {
        return array_values(array_filter(
            $this->products->gallery($productId),
            fn (string $mediaId): bool => $this->platform->media($mediaId)?->variantsStatus === MediaVariantsStatus::Ready,
        ));
    }

    /** Whether this photo is the only ready one the product has. */
    public function isLast(string $productId, string $mediaId): bool
    {
        return $this->of($productId) === [strtolower($mediaId)];
    }
}
