<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Catalog;

use Illuminate\Contracts\Translation\Translator;
use Modules\Catalog\Public\Contracts\ProductStoreSection;
use Modules\Inventory\Application\Query\ViewProductStock\ViewProductStock;
use Modules\Inventory\Application\Query\ViewProductStock\ViewProductStockHandler;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * **Inventory's part of a product's Stores tab** (inventory.md §4.3.3, the product page's Inventory
 * section; Catalog's amendment 23(e)): `ViewProductStock` once for every store card, each card's part
 * handed to Catalog as plain data, drawn by `resources/js/sections/Inventory/ProductStock` (stage 5's
 * screens step 5 - until then the card draws nothing for it: a component the build does not have draws
 * nothing). A store where the reader does not manage stock draws nothing.
 *
 * Catalog's page carries no word of Inventory's, so each card's part carries the words its rows and
 * dialogs say (`inventory::admin_stock`, in the page's language), as Pricing's section does. No query:
 * the words are files.
 */
final readonly class ProductStockSection implements ProductStoreSection
{
    /** The words the section and its dialogs say. */
    private const string WORDS = 'inventory::admin_stock';

    public function __construct(
        private ViewProductStockHandler $handler,
        private Translator $translator,
    ) {}

    public function component(): string
    {
        return 'Inventory/ProductStock';
    }

    public function read(string $productId, array $variantIds, array $stores): array
    {
        $cards = [];
        $words = null;

        foreach ($this->handler->handle(new ViewProductStock($productId, $variantIds, array_map(static fn (StoreDto $store): string => $store->id, $stores))) as $card) {
            /** @var array<string, mixed> $plain */
            $plain = self::plain($card);
            $words ??= $this->words();
            $cards[$card->storeId] = [...$plain, 'words' => $words];
        }

        return $cards;
    }

    /**
     * Inventory's words for the section, flattened as a page receives its own: "inventory::admin_stock.reasons.wired".
     *
     * @return array<string, string>
     */
    private function words(): array
    {
        $file = $this->translator->get(self::WORDS);

        return is_array($file) ? self::flatten($file, self::WORDS) : [];
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return array<string, string>
     */
    private static function flatten(array $lines, string $prefix): array
    {
        $flat = [];

        foreach ($lines as $key => $line) {
            if (is_array($line)) {
                $flat = [...$flat, ...self::flatten($line, "{$prefix}.{$key}")];
            } elseif (is_string($line)) {
                $flat["{$prefix}.{$key}"] = $line;
            }
        }

        return $flat;
    }

    /**
     * An answer as plain data: each object its public fields, each list its items.
     */
    private static function plain(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? array_map(self::plain(...), $value) : $value;
    }
}
