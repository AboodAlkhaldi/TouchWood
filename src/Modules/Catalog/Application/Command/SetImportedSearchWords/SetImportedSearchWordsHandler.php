<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetImportedSearchWords;

use Modules\Catalog\Application\Import\FileProduct;
use Modules\Catalog\Application\Import\ImportedProductsChange;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\TooMany;
use Modules\Catalog\Domain\ValueObject\SearchWords;
use Shared\Application\Unauthorized;

/**
 * **Search words for an import's products** (catalog.md §1.12, amendment 7(c), (d)): added to each
 * one's words, replacing them, or only for the products that have none — each word as the panel takes
 * it (§1.1), a duplicate kept once, at most 30 a product: a product they would take past it is named
 * and nothing changes.
 */
final readonly class SetImportedSearchWordsHandler
{
    public const string PERMISSION = ImportedProductsChange::PERMISSION;

    public function __construct(
        private ImportedProductsChange $change,
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

        return $this->change->run($command->importId, $command->productIds, 'search_words', implode(', ', $words), $mode, static function (FileProduct $product) use ($words, $mode): FileProduct {
            if ($mode === ImportedProductsChange::FILL_EMPTY && $product->searchWords !== []) {
                return $product;
            }

            try {
                $kept = self::words($mode === ImportedProductsChange::ADD ? [...$product->searchWords, ...$words] : $words);
            } catch (TooMany) {
                throw new InvalidCatalogAttribute("product {$product->number} › search_words", 'at most '.SearchWords::MAX);
            }

            return $product->with(['search_words' => $kept]);
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
