<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\ValueObject;

/**
 * One line of an order's hold (inventory.md §1.4, §4.1, §5): a size, bought or given, the pieces, and
 * whether they count on stock - in a store with no provider every line does; in a wired store a
 * stock-dependent size and a gift do, an ordinary product's line is only noted.
 */
final readonly class HoldLine
{
    public const string OPEN = 'OPEN';

    public const string SHIPPED = 'SHIPPED';

    public const string REDUCED = 'REDUCED';

    public const string RELEASED = 'RELEASED';

    public const string EXPIRED = 'EXPIRED';

    public function __construct(
        public string $variantId,
        public bool $gift,
        public int $quantity,
        public bool $countsOnStock,
        public int $shipped = 0,
        public string $ended = self::OPEN,
    ) {}

    public function key(): string
    {
        return self::keyOf($this->variantId, $this->gift);
    }

    public static function keyOf(string $variantId, bool $gift): string
    {
        return $variantId.($gift ? '|gift' : '|bought');
    }

    /**
     * The pieces this line holds on the size's stock now: none once it has ended or when it is only
     * noted; in a wired store all of it until the tick (shipping takes nothing there); otherwise what
     * is not shipped yet.
     */
    public function held(bool $wired): int
    {
        if (! $this->countsOnStock || $this->ended !== self::OPEN) {
            return 0;
        }

        return $wired ? $this->quantity : $this->quantity - $this->shipped;
    }
}
