<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\ValueObject;

/**
 * One size as the provider gives it (sync.md §2.1): its code, and its base price and stock as they came
 * - never trusted, each checked where it is written (Pricing's and Inventory's rules, sync.md §1.4,
 * §1.5). Either may be missing (§2.1) - unless the provider's own contract says every item carries it, when
 * its adapter names it in `missing` and the read reports it as a bad value (Odoo's, §2.4 As built).
 */
final readonly class ProviderValue
{
    /**
     * @param  list<'price'|'stock'>  $missing  the values the provider was bound to send and did not
     */
    public function __construct(
        public string $code,
        public mixed $price,
        public mixed $stock,
        public array $missing = [],
    ) {}
}
