<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Dto;

use Modules\Loyalty\Public\Enums\Audience;
use Shared\Domain\ValueObject\Money;

/**
 * What Sales asks while building its quote (loyalty.md §1.7, §2.2): how much of this order the
 * customer's points could pay.
 */
final readonly class RedemptionRequest
{
    public function __construct(
        public string $customerId,
        public string $storeId,
        public Audience $audience,
        /** The points asked for; null: as many as allowed — the only choice in ALL_OR_NOTHING. */
        public ?int $pointsAsked,
        /** In the store's currency. */
        public Money $netSubtotal,
    ) {}
}
