<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetSearchWords;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\TooMany;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\ValueObject\SearchWords;
use Shared\Application\Unauthorized;

/**
 * **A product's search words** (catalog.md §1.1, §1.11): `catalog.product.update`, as its shared data.
 * Kept as typed and as search reads them; a duplicate, typed twice or spelt two ways search reads as
 * one, is kept once, quietly (amendment 3(f)).
 */
final readonly class SetSearchWordsHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_UPDATE;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private ProductEvents $events,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ProductNotFound|TooMany|Unauthorized
     */
    public function handle(SetSearchWords $command): void
    {
        $this->access->authorize(self::PERMISSION);
        $words = SearchWords::of($command->words);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command, $words): array {
            $product = $this->products->byId($command->productId) ?? throw new ProductNotFound($command->productId);
            $this->access->authorizeFor(self::PERMISSION, $product->id());
            $before = SearchWords::reconstitute($this->products->searchWords($product->id()));

            if ($before->words === $words->words) {
                return [null, []];
            }

            $this->products->replaceSearchWords($product->id(), $words->words);
            $this->events->changed($product);

            return [null, [ListAudit::replaced('product', 'search_words_changed', $product->id(), 'search_words', $before->asText(), $words->asText())]];
        });
    }
}
