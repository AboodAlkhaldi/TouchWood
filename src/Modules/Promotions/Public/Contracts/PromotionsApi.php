<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Contracts;

use Modules\Promotions\Public\Dto\CouponRequest;
use Modules\Promotions\Public\Dto\CouponResult;
use Modules\Promotions\Public\Dto\EditedCouponRequest;
use Modules\Promotions\Public\Dto\GiftOfferDto;
use Modules\Promotions\Public\Dto\RecordOrder;
use Modules\Promotions\Public\Dto\UpdateCouponUse;
use Modules\Promotions\Public\Dto\UseCoupon;
use Modules\Promotions\Public\Enums\Audience;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * What Sales asks Promotions (promotions.md §2.1): ids and values in, DTOs out (handoff §4.3). The
 * reads have no permission of their own — they serve flows their callers have authorized. The three
 * writes are Sales's, made inside its checkout's or its edit's transaction, and name the Sales
 * permission that allowed them; Promotions builds the scope from the coupon's or the order's store.
 */
interface PromotionsApi
{
    /**
     * Sales's cart and quote (§1.3): what the coupon takes off, line by line, or why it is refused —
     * whole, never trimmed. Whether the order also uses points is decided after it: a coupon that does
     * not allow them means the order uses none (`allowWithPoints`). Reads only.
     */
    public function applyCoupon(CouponRequest $request): CouponResult;

    /**
     * Sales's coupon box (§2.1, answer 29): whether the code typed names a coupon of the store this
     * customer may know of — one for everyone, or one assigned to them — whatever its dates or uses, so
     * Sales can count the codes that name nothing. Reads only.
     */
    public function knowsCoupon(StoreId $store, string $customerId, string $code): bool;

    /**
     * The room left under the store's discount ceiling for a coupon, then points (§1.6): what the
     * ceiling allows off `gross_subtotal`, less what the prices already took, never below 0.
     */
    public function customerDiscountRoom(StoreId $store, Money $grossSubtotal, Money $netSubtotal): Money;

    /**
     * Placing the order (§2.1): every check of §1.3 again, as the coupon is now, under a lock on the
     * coupon; refused (`CouponRefused`) or changed (`CouponChanged`) rather than ever applying anything
     * the customer did not see; then the use recorded. The same order and coupon again does nothing;
     * another coupon on the order is refused. A use never comes back.
     */
    public function useCoupon(UseCoupon $change): void;

    /**
     * Placing the order, with or without a coupon (§1.5): the fact segments count, with the store's
     * ceiling of that moment. The same order again does nothing.
     */
    public function recordOrder(RecordOrder $change): void;

    /** A staff edit (§1.6): the room under the ceiling **recorded with the order**, by the same formula. */
    public function orderDiscountRoom(string $orderId, Money $grossSubtotal, Money $netSubtotal): Money;

    /**
     * After a staff edit (§1.3, the exception): the order's coupon worked out on the edited lines, as
     * it is now but ignoring its dates, switch, uses and assignment — never more than its discount now,
     * trimmed to the room only when it alone passes it, refused (0) when the edited order is under its
     * minimum or nothing is eligible any more. Reads only.
     */
    public function couponAfterEdit(EditedCouponRequest $request): CouponResult;

    /** After a staff edit: the use's new discount, never higher. Once per edit. */
    public function updateCouponUse(UpdateCouponUse $change): void;

    /**
     * Ops later — newsletters, notifications (§1.5: segments are reusable): a segment's customer ids,
     * keyset-paged by id, at most `$limit` (1 to 1000) after `$after`. Reads only.
     *
     * @return list<string>
     */
    public function segmentMembers(string $segmentId, ?string $after, int $limit): array;

    /**
     * Sales's cart, quote and checkout (§1.7): the gift levels an order of `$goodsTotal` reaches now for
     * this buyer — switched on, within their dates, their amount at most the goods total (after coupon
     * and points) — highest first. Sales asks Inventory which gift is in stock and holds the first that
     * is. The total must be in the store's currency. Reads only.
     *
     * @return list<GiftOfferDto>
     */
    public function giftLevels(StoreId $store, Audience $audience, Money $goodsTotal): array;

    /**
     * Sales's cart (the next gift): the gift levels an order of `$goodsTotal` does not reach yet for
     * this buyer — switched on, within their dates, their amount above the goods total — lowest first.
     * Sales asks Inventory which gift is in stock and shows the first that is. The total must be in the
     * store's currency. Reads only.
     *
     * @return list<GiftOfferDto>
     */
    public function giftLevelsAbove(StoreId $store, Audience $audience, Money $goodsTotal): array;
}
