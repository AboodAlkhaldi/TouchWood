<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A hold one line short (inventory.md §1.4, §2.1, §7): all or nothing, so nothing is held. Its context
 * names the short variants and what each had free, in the same order (`variants`, `available`, each
 * comma-separated: a context holds plain values only).
 */
final class NotEnoughStock extends InventoryError
{
    /**
     * @param  array<string, int>  $available  each short variant => the pieces it had free
     */
    public function __construct(public readonly array $available = [])
    {
        parent::__construct('Not enough stock for every line.');
    }

    public function type(): string
    {
        return 'inventory.not_enough_stock';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return [
            'variants' => implode(',', array_keys($this->available)),
            'available' => implode(',', $this->available),
        ];
    }
}
