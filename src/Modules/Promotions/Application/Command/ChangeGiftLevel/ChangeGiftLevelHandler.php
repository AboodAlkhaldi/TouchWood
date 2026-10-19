<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\ChangeGiftLevel;

use Carbon\CarbonImmutable;
use Modules\Promotions\Application\Coupon\CouponAudit;
use Modules\Promotions\Application\Gift\GiftLevelChecks;
use Modules\Promotions\Application\MarketingChange;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Exception\GiftLevelClash;
use Modules\Promotions\Domain\Exception\GiftLevelNotFound;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Repository\GiftLevels;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Changes a gift level's amount, gift, buyers or dates (promotions.md §1.7, §3) — for every later order;
 * a placed order keeps the gift it had. The level's row is locked first. An active level is checked for
 * a clash again.
 *
 * **Only a new gift is checked against Catalog**, as only a coupon's new list entries are (owner,
 * 2026-10-19, answer 28): a level whose variant
 * was archived since keeps it — Sales finds it out of stock and goes a level down — so changing the
 * dates is never blocked by the old gift.
 */
final readonly class ChangeGiftLevelHandler
{
    public const string PERMISSION = PromotionsPermissions::GIFT_MANAGE;

    public function __construct(
        private MarketingChange $change,
        private GiftLevels $levels,
        private GiftLevelChecks $checks,
    ) {}

    /**
     * @throws Unauthorized|GiftLevelNotFound|InvalidPromotionAttribute|GiftLevelClash
     */
    public function handle(ChangeGiftLevel $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $this->change->giftLevelStore(self::PERMISSION, $command->levelId));
        $terms = $command->fields->terms($store->currencyCode);
        $storeId = StoreId::fromString($store->id);

        $this->change->run($store, function () use ($storeId, $terms, $command): array {
            $level = $this->levels->lock($storeId, $command->levelId) ?? throw new GiftLevelNotFound;

            if ($terms->variantId !== $level->variantId) {
                $this->checks->variant($terms->variantId);
            }

            if ($level->active) {
                $this->checks->noClash($storeId, $terms, $level->id);
            }

            $entry = CouponAudit::entry('updated', $level->id, $storeId->value, $level->terms()->snapshot(), $terms->snapshot(), 'gift_level');

            if ($entry !== null) {
                $this->levels->change($level, $terms, CarbonImmutable::now());
            }

            return [null, array_filter([$entry])];
        });
    }
}
