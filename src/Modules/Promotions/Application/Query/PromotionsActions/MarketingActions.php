<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\PromotionsActions;

/** What a reader may change in one store's Marketing screens (promotions.md §4.1, §3). */
final readonly class MarketingActions
{
    public function __construct(
        public bool $mayManageCoupons,
        public bool $mayManageSegments,
        public bool $mayManageGifts,
    ) {}
}
