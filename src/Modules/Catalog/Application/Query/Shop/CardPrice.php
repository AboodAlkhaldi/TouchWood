<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * **A card's price for its viewer** (amendment 35; the shop's screens' B8), in the store's smallest unit,
 * without VAT, as Pricing pushed it: every viewer sees retail — `$now` and `$before` (only while `$now` is
 * lower) of the card's retail size, and `$from`, the lowest `$now` among the retail sizes, only when they
 * differ — and a company or a guest also `$wholesaleFrom`, the least one piece costs on a wholesale line
 * of any size that sells wholesale, where companies may buy it. A part with no price is null; a card with
 * none has no price.
 */
final readonly class CardPrice
{
    public function __construct(
        public ?int $now,
        public ?int $before,
        public ?int $from,
        public ?int $wholesaleFrom,
    ) {}

    public static function of(?int $now, ?int $before, ?int $from, ?int $wholesaleFrom): ?self
    {
        return $now === null && $from === null && $wholesaleFrom === null ? null : new self($now, $now === null ? null : $before, $from, $wholesaleFrom);
    }
}
