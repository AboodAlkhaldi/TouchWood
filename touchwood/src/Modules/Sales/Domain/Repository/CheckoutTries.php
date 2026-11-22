<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Repository;

use DateTimeImmutable;
use Modules\Sales\Domain\Model\CheckoutTry;

/** The tries (sales.md §9.5 #4, #11): one live per cart, one per quote not released. */
interface CheckoutTries
{
    public function add(CheckoutTry $try): void;

    public function find(string $tryId): ?CheckoutTry;

    /** As `find`, its row locked until the transaction ends. */
    public function lock(string $tryId): ?CheckoutTry;

    /** The try made under this future order id, whatever its state. */
    public function ofOrder(string $orderId): ?CheckoutTry;

    /** The cart's live try. */
    public function liveOfCart(string $cartId): ?CheckoutTry;

    /** The quote's try that is not released. */
    public function keptOfQuote(string $quoteId): ?CheckoutTry;

    /** Ends a live try, its copies of the address and contact gone with it. */
    public function end(string $tryId, string $state, DateTimeImmutable $at): void;

    /** Adds what is to be given back to the customer by hand (§9.5 #4). */
    public function addRefundDue(string $tryId, int $amount): void;

    public function markForAttention(string $tryId): void;

    /** The customer's paid try in the store they have not yet been shown, the latest. */
    public function unseenPaid(string $storeId, string $customerId): ?CheckoutTry;

    /** @return bool whether this was the first time */
    public function markSeen(string $tryId, DateTimeImmutable $at): bool;

    /**
     * Stock removed under a live try: the flag its order carries when it is made (§1.11).
     *
     * @param  list<string>  $orderIds
     */
    public function flagShort(string $storeId, array $orderIds, DateTimeImmutable $at): void;

    /**
     * Live tries whose window ended before this moment, the oldest first.
     *
     * @return list<CheckoutTry>
     */
    public function livePast(DateTimeImmutable $at, int $most): array;
}
