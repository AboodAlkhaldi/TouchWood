<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Enums;

/**
 * How a product is sold, after its price - "SAR / per piece" (shop-design.md §10.1, C4, D2): one of a fixed list, a
 * piece unless chosen. Public, as Sales shows it on a cart's, an order's and an email's lines (§10.7, Q2).
 */
enum ProductUnit: string
{
    case Piece = 'PIECE';
    case Set = 'SET';
    case Pair = 'PAIR';
    case Metre = 'METRE';
    case Carton = 'CARTON';
    case Box = 'BOX';
}
