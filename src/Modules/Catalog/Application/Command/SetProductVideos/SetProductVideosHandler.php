<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetProductVideos;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Application\Products\ProductVideos;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\TooMany;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\ValueObject\ProductVideo;
use Shared\Application\Unauthorized;

/**
 * **A product's videos** (flags-2026-11.md S4.4, catalog.md amendment 48): `catalog.product.update`, as its gallery -
 * at most 10 YouTube videos, each once, in the order given, only each video's id kept, with an optional title per
 * language. The product's, not a size's (F22); never in the products file (F23).
 *
 * Read and refused whole before anything is locked; written under the products' lock, which tells the shop's kept
 * pages (DatabaseListLocks). Nothing above Catalog hears of it: no other module reads a product's videos, so no
 * `ProductChanged` is sent (Pricing would reprice for it).
 */
final readonly class SetProductVideosHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_UPDATE;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ProductNotFound|TooMany|Unauthorized
     */
    public function handle(SetProductVideos $command): void
    {
        $this->access->authorize(self::PERMISSION);
        $after = ProductVideos::read($command->videos);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command, $after): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $this->access->authorizeFor(self::PERMISSION, $product->id());
            $before = $this->products->videos($product->id());

            if (self::rows($before) === self::rows($after)) {
                return [null, []];
            }

            $this->products->replaceVideos($product->id(), $after);

            return [null, [ListAudit::replaced('product', 'videos_changed', $product->id(), 'videos', self::described($before), self::described($after))]];
        });
    }

    /**
     * @param  list<ProductVideo>  $videos
     * @return list<array{string, string|null, string|null}>
     */
    private static function rows(array $videos): array
    {
        return array_map(static fn (ProductVideo $video): array => [$video->videoId, $video->titleAr, $video->titleEn], $videos);
    }

    /**
     * @param  list<ProductVideo>  $videos
     */
    private static function described(array $videos): ?string
    {
        return $videos === [] ? null : implode(', ', array_map(static fn (ProductVideo $video): string => $video->described(), $videos));
    }
}
