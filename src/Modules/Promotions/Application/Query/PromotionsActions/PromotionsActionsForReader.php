<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\PromotionsActions;

use Modules\Promotions\Application\PromotionsPermissions;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * Which of the Marketing screens' buttons the person looking may be offered in a store (promotions.md
 * §4.1, owner's Q16): asked here because a screen may not ask the authorizer itself
 * (tests/Architecture/AccessDecisionsTest). A reader without a job sees none of its buttons; offering
 * is never allowing - every handler behind a button asks again (handoff §19).
 */
final readonly class PromotionsActionsForReader
{
    public function __construct(
        private Authorizer $authorizer,
    ) {}

    public function forStore(StoreId $store): MarketingActions
    {
        return new MarketingActions(
            $this->holds(PromotionsPermissions::COUPON_MANAGE, $store),
            $this->holds(PromotionsPermissions::SEGMENT_MANAGE, $store),
            $this->holds(PromotionsPermissions::GIFT_MANAGE, $store),
        );
    }

    private function holds(string $permission, StoreId $store): bool
    {
        $stores = $this->authorizer->storesWith($permission);

        if ($stores === null) {
            return true;
        }

        foreach ($stores as $held) {
            if ($held->equals($store)) {
                return true;
            }
        }

        return false;
    }
}
