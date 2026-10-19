<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Catalog;

use Modules\Catalog\Public\Contracts\ProductStoreSection;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Application\Query\ViewProductPrices\ViewProductPrices;
use Modules\Pricing\Application\Query\ViewProductPrices\ViewProductPricesHandler;

/**
 * **Pricing's part of a product's Stores tab** (pricing.md §4.3.3, the product page; Catalog's amendment
 * 23(e)): `ViewProductPrices` once for every store card, each card's part handed to Catalog as plain data
 * - the amounts already written - and drawn by `resources/js/sections/Pricing/ProductPrices` (stage 5's
 * screens step 3). A store where the reader holds none of Pricing's jobs draws nothing.
 */
final readonly class ProductPricesSection implements ProductStoreSection
{
    public function __construct(
        private ViewProductPricesHandler $handler,
    ) {}

    public function component(): string
    {
        return 'Pricing/ProductPrices';
    }

    public function read(string $productId, array $variantIds, array $stores): array
    {
        $cards = [];

        foreach ($this->handler->handle(new ViewProductPrices($productId, $variantIds, array_map(static fn (StoreDto $store): string => $store->id, $stores))) as $card) {
            /** @var array<string, mixed> $plain */
            $plain = self::plain($card);
            $cards[$card->storeId] = $plain;
        }

        return $cards;
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
