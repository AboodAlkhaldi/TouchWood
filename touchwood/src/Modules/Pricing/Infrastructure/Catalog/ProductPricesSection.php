<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Catalog;

use Illuminate\Contracts\Translation\Translator;
use Modules\Catalog\Public\Contracts\ProductStoreSection;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Application\Query\ViewProductPrices\ViewProductPrices;
use Modules\Pricing\Application\Query\ViewProductPrices\ViewProductPricesHandler;

/**
 * **Pricing's part of a product's Stores tab** (pricing.md §4.3.3, the product page; Catalog's amendment
 * 23(e)): `ViewProductPrices` once for every store card, each card's part handed to Catalog as plain data
 * - the amounts already written - and drawn by `resources/js/sections/Pricing/ProductPrices` (stage 5's
 * screens step 3). A store where the reader holds none of Pricing's jobs draws nothing.
 *
 * Catalog's page carries no word of Pricing's, so each card's part carries the words its rows and dialogs
 * say (`pricing::admin`, in the page's language) - the section's "plain data only, already in the page's
 * language" (`ProductStoreSection`). No query: the words are files.
 */
final readonly class ProductPricesSection implements ProductStoreSection
{
    /** The words the section and its dialogs say. */
    private const string WORDS = 'pricing::admin';

    public function __construct(
        private ViewProductPricesHandler $handler,
        private Translator $translator,
    ) {}

    public function component(): string
    {
        return 'Pricing/ProductPrices';
    }

    public function read(string $productId, array $variantIds, array $stores): array
    {
        $cards = [];
        $words = null;

        foreach ($this->handler->handle(new ViewProductPrices($productId, $variantIds, array_map(static fn (StoreDto $store): string => $store->id, $stores))) as $card) {
            /** @var array<string, mixed> $plain */
            $plain = self::plain($card);
            $words ??= $this->words();
            $cards[$card->storeId] = [...$plain, 'words' => $words];
        }

        return $cards;
    }

    /**
     * Pricing's words for the section, flattened as a page receives its own: "pricing::admin.badge.offer".
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
