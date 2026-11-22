<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\CreateCoupon;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Modules\Promotions\Application\Coupon\CouponAudit;
use Modules\Promotions\Application\Coupon\Inputs;
use Modules\Promotions\Application\Coupon\RuleTargets;
use Modules\Promotions\Application\MarketingChange;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Exception\CodeTaken;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Repository\Coupons;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Creates a store's coupon (promotions.md §1.1, §3): its code read as typed (case, Arabic digits) and
 * never changed after, unique in the store; its terms and lists checked; audited.
 */
final readonly class CreateCouponHandler
{
    public const string PERMISSION = PromotionsPermissions::COUPON_MANAGE;

    public function __construct(
        private MarketingChange $change,
        private Coupons $coupons,
        private RuleTargets $targets,
    ) {}

    /**
     * @throws Unauthorized|InvalidPromotionAttribute|CodeTaken
     */
    public function handle(CreateCoupon $command): string
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);
        $code = Inputs::code($command->code) ?? throw new InvalidPromotionAttribute('code', '3 to 30 Latin letters, digits and hyphens');
        $terms = $command->fields->terms($store->currencyCode);
        $this->targets->check($terms->rules);
        $storeId = StoreId::fromString($store->id);

        try {
            return $this->change->run($store, function () use ($storeId, $code, $terms, $command): array {
                if ($this->coupons->codeTaken($storeId, $code)) {
                    throw new CodeTaken($code);
                }

                $couponId = strtolower((string) Str::ulid());
                $this->coupons->create($couponId, $storeId, $code, $terms, $command->active, CarbonImmutable::now());

                return [$couponId, array_filter([CouponAudit::entry('created', $couponId, $storeId->value, [], ['code' => $code, 'active' => $command->active, ...$terms->snapshot()])])];
            });
        } catch (UniqueConstraintViolationException) {
            // The store's lock makes this a backstop: the code taken by a write that skipped it.
            throw new CodeTaken($code);
        } catch (QueryException $failed) {
            throw RuleTargets::removedMeanwhile($failed);
        }
    }
}
