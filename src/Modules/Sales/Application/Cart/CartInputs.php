<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Cart;

use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Model\CartLine;
use Shared\Infrastructure\Persistence\Ulids;

/** What a cart is changed with, read once for every command. */
final class CartInputs
{
    /** @throws InvalidSalesAttribute */
    public static function variant(string $variantId): string
    {
        $id = strtolower(trim($variantId));

        return Ulids::valid($id) ? $id : throw new InvalidSalesAttribute('variant', 'not a size');
    }

    /** @throws InvalidSalesAttribute */
    public static function mode(string $mode): SaleMode
    {
        return SaleMode::tryFrom(strtoupper(trim($mode))) ?? throw new InvalidSalesAttribute('sale_mode', 'retail or wholesale');
    }

    /** @throws InvalidSalesAttribute */
    public static function quantity(int $quantity): int
    {
        return $quantity >= 1 && $quantity <= CartLine::MOST ? $quantity : throw new InvalidSalesAttribute('quantity', '1 to '.CartLine::MOST);
    }
}
