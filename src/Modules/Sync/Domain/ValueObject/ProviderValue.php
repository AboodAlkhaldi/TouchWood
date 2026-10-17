<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\ValueObject;

/**
 * One size as the provider gives it (sync.md §2.1): its code, and its base price and stock as they came
 * - never trusted, each checked where it is written (Pricing's and Inventory's rules, sync.md §1.4,
 * §1.5). Either may be missing.
 */
final readonly class ProviderValue
{
    public function __construct(
        public string $code,
        public mixed $price,
        public mixed $stock,
    ) {}
}
