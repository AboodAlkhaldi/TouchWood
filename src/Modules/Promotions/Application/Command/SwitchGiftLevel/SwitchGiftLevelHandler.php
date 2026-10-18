<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\SwitchGiftLevel;

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
 * Switches a gift level off — no order gets its gift — or on again (promotions.md §1.7, §4); there is no
 * deleting one, as a placed order names it. Switched on, it is checked for a clash. Already so: nothing,
 * and nothing audited.
 */
final readonly class SwitchGiftLevelHandler
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
    public function handle(SwitchGiftLevel $command): void
    {
        $store = $this->change->authorize(self::PERMISSION, $this->change->giftLevelStore(self::PERMISSION, $command->levelId));
        $storeId = StoreId::fromString($store->id);

        $this->change->run($store, function () use ($storeId, $command): array {
            $level = $this->levels->lock($storeId, $command->levelId) ?? throw new GiftLevelNotFound;

            if ($level->active === $command->active) {
                return [null, []];
            }

            if ($command->active) {
                $this->checks->noClash($storeId, $level->terms(), $level->id);
            }

            $this->levels->switchTo($level, $command->active, CarbonImmutable::now());

            return [null, array_filter([CouponAudit::entry($command->active ? 'activated' : 'deactivated', $level->id, $storeId->value, ['active' => $level->active], ['active' => $command->active], 'gift_level')])];
        });
    }
}
