<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\ValueObject;

/**
 * **A note Inventory writes itself on a ledger line** (inventory.md §1.6; stage 5 step 10): kept as a code
 * and its values, and written in the reader's language when the history shows it - as Sync keeps its own
 * reasons (sync.md Q31). A note typed by staff is kept as typed instead; a line written before the codes
 * keeps its English text and is shown as it is.
 *
 * - `SHIPPED_BEYOND_COUNT` - a shipment took the stock to 0 with pieces still to go (sales.md answer 41):
 *   `count`, the pieces shipped beyond the count, for a recount.
 * - `UNWIRED` - the store was unwired and the pieces returned while the provider ruled were added
 *   (sync.md §9.2 #12): no values.
 */
final readonly class LedgerNote
{
    public const string SHIPPED_BEYOND_COUNT = 'SHIPPED_BEYOND_COUNT';

    public const string UNWIRED = 'UNWIRED';

    /** Every code the ledger accepts (its `stock_movements_note_code` check). */
    public const array CODES = [self::SHIPPED_BEYOND_COUNT, self::UNWIRED];

    /**
     * @param  array<string, int>  $values
     */
    private function __construct(
        public string $code,
        public array $values,
    ) {}

    public static function shippedBeyondCount(int $count): self
    {
        return new self(self::SHIPPED_BEYOND_COUNT, ['count' => $count]);
    }

    public static function unwired(): self
    {
        return new self(self::UNWIRED, []);
    }

    /**
     * A stored code and its values read back; null for a code the ledger does not know.
     *
     * @param  array<array-key, mixed>  $values
     */
    public static function stored(string $code, array $values): ?self
    {
        if (! in_array($code, self::CODES, true)) {
            return null;
        }

        $kept = [];

        foreach ($values as $name => $value) {
            if (is_string($name) && is_int($value)) {
                $kept[$name] = $value;
            }
        }

        return new self($code, $kept);
    }
}
