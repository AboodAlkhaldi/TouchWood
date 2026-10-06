<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetImportedSearchWords;

use Modules\Catalog\Application\Import\FileProduct;
use Modules\Catalog\Application\Import\ImportedProductsChange;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\TooMany;
use Modules\Catalog\Domain\Model\Product;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\ValueObject\SearchWords;
use Shared\Application\Unauthorized;

/**
 * **Search words for an import's products** (catalog.md §1.12, amendment 7(c), (d), 8(a)): added to
 * each one's words, replacing them, or only for the products that have none — each word as the panel
 * takes it (§1.1), a duplicate kept once, at most 30 a product: a product they would take past it is
 * named and nothing changes. Where the file gives none, what is added or filled is kept apart and given
 * when the products are brought in, to what each then has (`FileProduct::asBroughtIn`).
 */
final readonly class SetImportedSearchWordsHandler
{
    public const string PERMISSION = ImportedProductsChange::PERMISSION;

    public function __construct(
        private ImportedProductsChange $change,
        private ProductRepository $products,
    ) {}

    /**
     * @return int how many products it changed
     *
     * @throws ImportClosed|InvalidCatalogAttribute|ListItemNotFound|TooMany|Unauthorized
     */
    public function handle(SetImportedSearchWords $command): int
    {
        $this->change->authorize();
        $mode = ImportedProductsChange::mode($command->mode, [ImportedProductsChange::ADD, ImportedProductsChange::REPLACE, ImportedProductsChange::FILL_EMPTY]);
        $words = self::words($command->words === [] ? throw new InvalidCatalogAttribute('words', 'at least one word') : $command->words);

        return $this->change->run($command->importId, $command->productIds, 'search_words', implode(', ', $words), $mode, function (FileProduct $product, ?Product $updates) use ($words, $mode): FileProduct {
            try {
                if ($mode === ImportedProductsChange::REPLACE) {
                    return $product->with(['search_words' => $words, 'added_search_words' => [], 'fill_search_words' => []]);
                }

                // The file gives its own: they are what it has, whatever a catalog product it updates has.
                if ($product->searchWords !== []) {
                    return $mode === ImportedProductsChange::ADD ? $product->with(['search_words' => self::words([...$product->searchWords, ...$words])]) : $product;
                }

                // The file gives none: what the product has is known when it is brought in — the catalog's,
                // for one the file updates (§1.12) — so what is asked is kept, and given then.
                if ($mode === ImportedProductsChange::FILL_EMPTY) {
                    return $product->addedSearchWords !== [] || $product->fillSearchWords !== [] ? $product : $product->with(['fill_search_words' => $words]);
                }

                $added = self::words([...$product->addedSearchWords, ...$words]);
                // Within the limit together with what it would have now.
                $has = $updates === null ? [] : array_map(static fn (array $word): string => $word['word'], $this->products->searchWords($updates->id()));
                self::words([...($has !== [] ? $has : $product->fillSearchWords), ...$added]);

                return $product->with(['added_search_words' => $added]);
            } catch (TooMany) {
                throw new InvalidCatalogAttribute("product {$product->number} › search_words", 'at most '.SearchWords::MAX);
            }
        });
    }

    /**
     * @param  array<array-key, mixed>  $words
     * @return list<string>
     *
     * @throws InvalidCatalogAttribute|TooMany
     */
    private static function words(array $words): array
    {
        return array_map(static fn (array $word): string => $word['word'], SearchWords::of($words)->words);
    }
}
