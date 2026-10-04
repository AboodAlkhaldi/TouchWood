<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Lists;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Public\Enums\ProductFate;

/**
 * Each product's fate when its category or brand is deactivated (catalog.md §1.5, §1.6), as the
 * request sent it: one per product, and an "apply to all" for the rest. A product the deactivation
 * reaches with neither is refused, as is a product it does not reach, so every product ends with a
 * choice. A move with nowhere to go is refused where the move happens. A deactivation that changes
 * nothing — the category or brand already off — reads no choice at all.
 */
final readonly class ProductFates
{
    /**
     * @param  array<string, array{ProductFate, ?string}>  $byProduct  product id => its fate and where it moves
     */
    private function __construct(
        private ?ProductFate $every,
        private ?string $moveTo,
        private array $byProduct,
    ) {}

    /**
     * @param  array<array-key, mixed>  $products  product id => ['choice' => 'HIDE'|'LEAVE'|'MOVE', 'move_to' => ?string]
     * @param  list<ProductFate>  $allowed
     *
     * @throws InvalidCatalogAttribute
     */
    public static function of(?string $every, ?string $moveTo, array $products, array $allowed): self
    {
        $everyFate = $every === null ? null : self::fate($every, $allowed);
        $byProduct = [];

        foreach ($products as $productId => $choice) {
            if (! is_array($choice) || ! is_string($choice['choice'] ?? null)) {
                throw new InvalidCatalogAttribute('products', 'a choice for each product');
            }

            $fate = self::fate($choice['choice'], $allowed);
            $target = $choice['move_to'] ?? null;
            $byProduct[strtolower((string) $productId)] = [$fate, is_string($target) ? $target : null];
        }

        return new self($everyFate, $moveTo, $byProduct);
    }

    /**
     * @return array{ProductFate, ?string} the product's fate, and where it moves
     *
     * @throws InvalidCatalogAttribute
     */
    public function for(string $productId): array
    {
        return $this->byProduct[$productId] ?? ($this->every === null
            ? throw new InvalidCatalogAttribute('products', 'a choice for every product')
            : [$this->every, $this->moveTo]);
    }

    /**
     * Refuses a choice for a product the deactivation does not reach.
     *
     * @param  list<string>  $reached
     *
     * @throws InvalidCatalogAttribute
     */
    public function requireWithin(array $reached): void
    {
        if (array_diff(array_keys($this->byProduct), $reached) !== []) {
            throw new InvalidCatalogAttribute('products', 'products this deactivation reaches');
        }
    }

    /**
     * @param  list<ProductFate>  $allowed
     *
     * @throws InvalidCatalogAttribute
     */
    private static function fate(string $choice, array $allowed): ProductFate
    {
        $fate = ProductFate::tryFrom($choice);

        if ($fate === null || ! in_array($fate, $allowed, true)) {
            throw new InvalidCatalogAttribute('choice', 'one of '.implode(', ', array_map(static fn (ProductFate $allowed): string => $allowed->value, $allowed)));
        }

        return $fate;
    }
}
