<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Contracts;

use DateTimeImmutable;
use Modules\Sales\Public\Dto\MoneyDayDto;
use Modules\Sales\Public\Dto\MoneyDaysDto;
use Modules\Sales\Public\Dto\MoneyRecordMessageDto;
use Modules\Sales\Public\Dto\OrderMessageDto;
use Modules\Sales\Public\Dto\ProductNetSalesPageDto;
use Modules\Sales\Public\Dto\ProductSalesPageDto;
use Modules\Sales\Public\Dto\ReturnMessageDto;
use Modules\Sales\Public\Dto\RevenueDayDto;
use Modules\Sales\Public\Dto\SalesCountsDto;
use Modules\Sales\Public\Dto\TransfersWaitingDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * What Sales offers Ops (sales.md §2.5; ops.md §1.5, §1.11, §2.3) — its own contract, apart from `SalesApi`
 * (Feedback's). Reads only, with no permission of their own: the caller asks who may see them. Ids in, DTOs
 * out; amounts in the order's or the store's currency, never converted.
 */
interface SalesReports
{
    /**
     * **An order as its messages need it**: number, state, lines and totals as sold (or as edited), way to
     * pay, carrier and number, where it goes (the recipient and the address formatted), the reasons staff
     * gave — names and values in `locale` (`ar`; anything else reads as English). Null for none. The
     * tracking link comes with stage 7's Shipping.
     */
    public function orderForMessage(string $orderId, string $locale): ?OrderMessageDto;

    /** **A return as its messages need it**: its order's number, state, the store's words when refused, the refund once completed. Null for none. */
    public function returnForMessage(string $returnId): ?ReturnMessageDto;

    /**
     * **A payment or a refund as its message needs it** (ops.md C9b, C10; the owner's go 2026-10-31): the
     * record `PaymentRecorded` or `RefundRecorded` names (`moneyRecordId`) — which it is, its order and
     * number, the amount and way, the day, and whether it was voided since. Null for none.
     */
    public function moneyRecordForMessage(string $moneyRecordId): ?MoneyRecordMessageDto;

    /**
     * **The daily report's orders** (ops.md §1.11 item 2) from `from` up to `to` (not included): placed —
     * individuals and companies apart — and of those the ones that count as sales; cancelled, rejected,
     * come back. One query.
     */
    public function counts(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): SalesCountsDto;

    /**
     * **The money moved** (ops.md §1.11 item 3), by the day it moved (`happened_on`, the store's own days)
     * from `fromDay` to `toDay`, both included — payments in and refunds out, by way; voided records never.
     * Two queries. The days are the store's own: pass each as its date there (only the date is read).
     */
    public function moneyOn(StoreId $store, DateTimeImmutable $fromDay, DateTimeImmutable $toDay): MoneyDaysDto;

    /**
     * **The money moved, day by day** (ops.md §1.10, the Excel "Money received" sheet): as `moneyOn`, but one
     * row per day with anything in it, from `fromDay` to `toDay` both included, oldest first — each day's
     * payments in and refunds out by way; voided records never. One query, however long the range. The days
     * are the store's own, as for `moneyOn` (only the date is read).
     *
     * @return list<MoneyDayDto>
     */
    public function moneyByDay(StoreId $store, DateTimeImmutable $fromDay, DateTimeImmutable $toDay): array;

    /**
     * **The bank transfers still awaited** (ops.md §1.11 item 3): open orders to be paid by transfer that
     * hold less than they owe, and how much. Overdue ones are stage 7's mark (sales.md §9.5 #9). A store
     * that does not exist is refused (`InvalidArgumentException`), as by the two reads below.
     */
    public function transfersWaiting(StoreId $store): TransfersWaitingDto;

    /**
     * **Revenue by the store's own day** (ops.md §1.10; the owner, 2026-10-28) from `from` up to `to` (not
     * included) — moments: pass the store's midnights for whole days — each day with anything in it:
     *
     * - the orders placed that count — **a cancelled, rejected or come-back order never does**, judged by
     *   what it is now, so a past day may change — and what their customers pay for the goods after the
     *   coupon and the points, **without VAT and without shipping**; their VAT and shipping apart;
     * - **a refund on the day its return was asked**: the goods refunded by those completed, and those not
     *   finished estimated with the same arithmetic (`ReturnRefunds`) over the pieces received, or else
     *   asked, in the order they were asked — each by return and order number, never a customer;
     * - **the goods' VAT those refunds return**, counted the same way (`refundsVat`, `waitingRefundsVat`, each
     *   waiting one's `estimateVat`; ops.md §1.11, the owner, 2026-10-30), so the VAT can be shown net of
     *   refunds as revenue is. The shipping's VAT never rides along, as the shipping never does — but `vat`
     *   is the orders' VAT as charged, the shipping's included.
     *
     * @return list<RevenueDayDto> by day
     */
    public function revenueByDay(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * **Each size's sales** (ops.md §1.10, §1.11 item 4) in the orders placed from `from` up to `to` (not
     * included) that count: units, and their totals less the coupon and points shares — without VAT;
     * the gift never. Most sold first, then by size; keyset-paged, `limit` 1 to 1000, a cursor that is
     * not one starting at the top. Returns are not taken off: they come off revenue by their own day.
     */
    public function productSales(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to, ?string $after = null, int $limit = 100): ProductSalesPageDto;

    /**
     * **Each product's sales net of its refunds** (ops.md §1.10, the Excel "By category" sheet; the owner,
     * 2026-10-30) from `from` up to `to` (not included): every product sold in the orders placed then that
     * count, or with a piece in a return asked then — its units and goods as `productSales` counts them, and
     * the goods refunded by those returns completed (`refunds`) and estimated for those not finished
     * (`waitingRefunds`), exactly as `revenueByDay` counts them, so the products' figures add up to its. By
     * product id; keyset-paged by it, `limit` 1 to 1000, a cursor that is not one starting at the top. A
     * store that does not exist is refused (`InvalidArgumentException`).
     */
    public function productNetSales(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to, ?string $after = null, int $limit = 100): ProductNetSalesPageDto;
}
