<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\EditOrder;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Dto\CallerPermission as LoyaltyCaller;
use Modules\Loyalty\Public\Dto\SettleEdit;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Pricing\Public\Dto\LinePriceDto;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Dto\CallerPermission as PromotionsCaller;
use Modules\Promotions\Public\Dto\UpdateCouponUse;
use Modules\Sales\Application\Order\EditPlan;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Order\OrderEditing;
use Modules\Sales\Application\Order\OrderInputs;
use Modules\Sales\Application\Order\SizeRecords;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\NotEditable;
use Modules\Sales\Domain\Exception\NotOrderable;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\SalesError;
use Modules\Sales\Domain\Model\EditedTotals;
use Modules\Sales\Domain\Model\NewOrderLine;
use Modules\Sales\Domain\Model\OrderForEdit;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Domain\Service\OrderShares;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/**
 * A staff edit confirmed (sales.md §1.7): worked out again under the order's lock, in one transaction —
 * the order kept as the edit leaves it (its lines, every share allocated again, its totals, gift and
 * address; what is due follows the new total, a difference settled by hand), the edit kept with its own
 * id and reason, then Promotions' `updateCouponUse`, Loyalty's `orderEdited`, and Inventory's
 * `adjustHold` last — all or nothing. Waiting or approved, unshipped, nothing ticked "reduced in the
 * provider" (`NotEditable`). The reason, typed by staff, stays on the edit's row; audited as written.
 * An edit does not clear "stock was reduced" (§1.11).
 */
final readonly class EditOrderHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_EDIT;

    public function __construct(
        private OrderAccess $access,
        private Orders $orders,
        private OrderEditing $editing,
        private SizeRecords $sizes,
        private PromotionsApi $promotions,
        private LoyaltyApi $loyalty,
        private InventoryApi $inventory,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return string the edit's id
     *
     * @throws Unauthorized|SalesError|DomainError
     */
    public function handle(EditOrder $command): string
    {
        [$orderId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->orderId);
        $reason = OrderInputs::words($command->reason);
        $wanted = OrderInputs::lines($command->lines);

        return $this->db->transaction(function () use ($orderId, $staffId, $reason, $wanted, $command): string {
            $state = $this->orders->lock($orderId) ?? throw new OrderNotFound;
            self::editable($state, $this->orders->ticked($state->id));
            $order = $this->orders->forEdit($state->id) ?? throw new OrderNotFound;
            $plan = $this->editing->plan($order, $wanted, $command->addressId, $command->gift);

            $editId = strtolower((string) Str::ulid());
            $now = CarbonImmutable::now();
            $lines = $this->lines($plan, $editId);

            $this->orders->addEdit($editId, $order->id, $order->storeId, $reason, $staffId, $now, self::summary($order), self::summaryOf($plan, $lines));
            $this->orders->replaceLines($order->id, $order->storeId, $lines);
            $this->orders->changeTotals($order->id, new EditedTotals(
                $plan->couponDiscount->minorUnits, $plan->pointsUsed, $plan->pointsDiscount->minorUnits, $plan->totals->grossSubtotal->minorUnits,
                $plan->totals->netSubtotal->minorUnits, $plan->totals->goodsTotal->minorUnits, $plan->shipping->minorUnits, $plan->totals->taxableBase->minorUnits,
                $plan->totals->vat->minorUnits, $plan->totals->orderTotal->minorUnits, $plan->giftLevelId,
                $plan->address === null ? null : [
                    'label' => $plan->address->label, 'recipientName' => $plan->address->recipientName, 'phone' => $plan->address->phone, 'fields' => $plan->address->fields,
                    'latitude' => $plan->address->latitude, 'longitude' => $plan->address->longitude, 'formatted' => $plan->address->formatted,
                ],
            ));
            $this->orders->recordHistory($order->id, $order->storeId, 'EDITED', $editId, $staffId, $now, ['orderTotal' => $plan->totals->orderTotal->minorUnits]);
            $changes = AuditChanges::none()->changed('edit_id', null, $editId)
                ->changed('order_total_minor', $order->orderTotal, $plan->totals->orderTotal->minorUnits)->personal('reason');
            $this->platform->recordAudit(new AuditEntryDto('sales.order.edited', 'sales.order', $order->id, $order->storeId, $plan->address === null ? $changes : $changes->personal('address')));

            // The other modules, Inventory last.
            if ($order->couponId !== null) {
                $this->promotions->updateCouponUse(new UpdateCouponUse($order->id, $editId, $plan->couponDiscount, new PromotionsCaller('sales', self::PERMISSION)));
            }

            if ($order->pointsUsed > 0) {
                $this->loyalty->orderEdited(new SettleEdit($order->id, $order->storeId, $editId, $plan->pointsDiscount, new LoyaltyCaller('sales', self::PERMISSION)));
            }

            $this->inventory->adjustHold($order->id, self::hold($lines));

            return $editId;
        }, 3);
    }

    /**
     * Waiting or approved, unshipped, nothing ticked (§1.7).
     *
     * @param  list<string>  $ticked
     *
     * @throws NotEditable
     */
    public static function editable(OrderState $order, array $ticked): void
    {
        if (! in_array($order->status, [OrderState::PENDING_APPROVAL, OrderState::APPROVED], true) || $order->shipmentStatus !== OrderState::NOT_SHIPPED || $ticked !== []) {
            throw new NotEditable;
        }
    }

    /**
     * The order's lines as the edit leaves them: the kept parts, the added pieces (each a part of this
     * edit), the gift — every share allocated again (§1.8).
     *
     * @return list<NewOrderLine>
     *
     * @throws NotOrderable
     */
    private function lines(EditPlan $plan, string $editId): array
    {
        $order = $plan->order;
        $prices = $plan->prices;
        $shares = OrderShares::of(
            array_map(static fn (LinePriceDto $line) => $line->total, $prices->lines), $plan->couponShares, $plan->pointsDiscount, $plan->totals->vat, $plan->shipping,
        );
        $existingGift = array_values(array_filter($order->lines, static fn ($line): bool => $line->isGift))[0] ?? null;
        $keepGift = $existingGift !== null && $plan->giftVariantId === $existingGift->variantId && $plan->giftLevelId === $order->giftLevelId;
        $newSizes = [...array_map(static fn ($line): string => $line->variantId, $plan->added), ...($plan->giftVariantId !== null && ! $keepGift ? [$plan->giftVariantId] : [])];
        $records = $this->sizes->of($newSizes);
        $stock = $order->wired && $plan->added !== [] ? $this->inventory->stock(StoreId::fromString($order->storeId), array_map(static fn ($line): string => $line->variantId, $plan->added)) : [];
        $lines = [];

        foreach ($prices->lines as $index => $priced) {
            $share = $shares['lines'][$index];
            $part = $plan->kept[$index] ?? null;

            if ($part !== null) {
                $line = $part['line'];
                $lines[] = new NewOrderLine(
                    $line->id, $line->productId, $line->variantId, false, $line->nameAr, $line->nameEn, $line->variantValues, $line->code,
                    $line->saleMode, $priced->kind->value, $priced->quantity, $priced->unit->minorUnits, $priced->listUnit->minorUnits, $priced->total->minorUnits,
                    $share['coupon']->minorUnits, $share['points']->minorUnits, $share['vat']->minorUnits, $line->countsOnStock, $line->editId,
                );

                continue;
            }

            $record = $records[$priced->variantId] ?? throw new NotOrderable([$priced->variantId]);
            $lines[] = new NewOrderLine(
                strtolower((string) Str::ulid()), $record['productId'], $priced->variantId, false, $record['nameAr'], $record['nameEn'], $record['values'], $record['code'],
                $priced->mode->value, $priced->kind->value, $priced->quantity, $priced->unit->minorUnits, $priced->listUnit->minorUnits, $priced->total->minorUnits,
                $share['coupon']->minorUnits, $share['points']->minorUnits, $share['vat']->minorUnits,
                // As the hold counts it: with no provider when placed, every piece; wired, the size's switch.
                $order->wired ? ($stock[$priced->variantId]->countsOnStock ?? false) : true,
                $editId,
            );
        }

        if ($keepGift) {
            $gift = $existingGift;
            $lines[] = new NewOrderLine(
                $gift->id, $gift->productId, $gift->variantId, true, $gift->nameAr, $gift->nameEn, $gift->variantValues, $gift->code,
                $gift->saleMode, null, 1, 0, 0, 0, 0, 0, 0, true, $gift->editId,
            );
        } elseif ($plan->giftVariantId !== null) {
            $record = $records[$plan->giftVariantId] ?? throw new NotOrderable([$plan->giftVariantId]);
            $lines[] = new NewOrderLine(
                strtolower((string) Str::ulid()), $record['productId'], $plan->giftVariantId, true, $record['nameAr'], $record['nameEn'], $record['values'], $record['code'],
                'RETAIL', null, 1, 0, 0, 0, 0, 0, 0, true, $editId,
            );
        }

        return $lines;
    }

    /**
     * What Inventory holds for the edited order: a line a size over both modes, and the gift's own.
     *
     * @param  list<NewOrderLine>  $lines
     * @return list<HoldLineDto>
     */
    private static function hold(array $lines): array
    {
        $pieces = [];

        foreach ($lines as $line) {
            $key = $line->variantId.($line->isGift ? ':gift' : '');
            $pieces[$key] = new HoldLineDto($line->variantId, ($pieces[$key]->quantity ?? 0) + $line->quantity, $line->isGift);
        }

        return array_values($pieces);
    }

    /** @return array<string, mixed> the order before the edit: its lines and totals — never an email or a phone */
    private static function summary(OrderForEdit $order): array
    {
        return [
            'lines' => array_map(static fn ($line): array => [
                'variantId' => $line->variantId, 'mode' => $line->saleMode, 'quantity' => $line->quantity, 'unit' => $line->unit, 'gift' => $line->isGift,
            ], $order->lines),
            'couponDiscount' => $order->couponDiscount, 'pointsDiscount' => $order->pointsDiscount, 'orderTotal' => $order->orderTotal, 'giftLevelId' => $order->giftLevelId,
        ];
    }

    /**
     * @param  list<NewOrderLine>  $lines
     * @return array<string, mixed> the order after the edit
     */
    private static function summaryOf(EditPlan $plan, array $lines): array
    {
        return [
            'lines' => array_map(static fn (NewOrderLine $line): array => [
                'variantId' => $line->variantId, 'mode' => $line->saleMode, 'quantity' => $line->quantity, 'unit' => $line->unit, 'gift' => $line->isGift,
            ], $lines),
            'couponDiscount' => $plan->couponDiscount->minorUnits, 'pointsDiscount' => $plan->pointsDiscount->minorUnits,
            'orderTotal' => $plan->totals->orderTotal->minorUnits, 'giftLevelId' => $plan->giftLevelId, 'addressChanged' => $plan->address !== null,
        ];
    }
}
