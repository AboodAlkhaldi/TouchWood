<?php

declare(strict_types=1);

namespace Modules\Shipping\Public\Dto;

use Modules\Shipping\Public\Enums\BoxSuggestionReason;

/**
 * The box suggested for an order's sizes (shipping.md §1.7, §2.1) - or "pack by hand", with why. Only a
 * suggestion: it never changes a price and never blocks anything.
 */
final readonly class BoxSuggestionDto
{
    public function __construct(
        public ?string $boxId,
        public ?string $boxName,
        public bool $byHand,
        public ?BoxSuggestionReason $reason,
    ) {}

    public static function box(string $boxId, string $boxName): self
    {
        return new self($boxId, $boxName, false, null);
    }

    public static function byHand(BoxSuggestionReason $reason): self
    {
        return new self(null, null, true, $reason);
    }
}
