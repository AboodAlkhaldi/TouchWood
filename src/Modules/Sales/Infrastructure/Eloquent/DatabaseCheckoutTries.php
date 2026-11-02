<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Domain\Model\CheckoutTry;
use Modules\Sales\Domain\Repository\CheckoutTries;
use stdClass;

/** `CheckoutTries` in SQL, no Eloquent model (sales.md §9.5 #11). */
final readonly class DatabaseCheckoutTries implements CheckoutTries
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function add(CheckoutTry $try): void
    {
        $this->db->table('sales.checkout_tries')->insert([
            'id' => $try->id,
            'store_id' => $try->storeId,
            'customer_id' => $try->customerId,
            'cart_id' => $try->cartId,
            'cart_version' => $try->cartVersion,
            'quote_id' => $try->quoteId,
            'order_id' => $try->orderId,
            'attempt_id' => $try->attemptId,
            'session_id' => $try->sessionId,
            'state' => $try->state,
            'amount_minor' => $try->amount,
            'currency_code' => $try->currencyCode,
            'window_ends_at' => Moment::of($try->windowEndsAt),
            'address' => json_encode($try->address, JSON_THROW_ON_ERROR),
            'contact' => json_encode($try->contact, JSON_THROW_ON_ERROR),
            'created_at' => Moment::of($try->createdAt),
        ]);
    }

    public function find(string $tryId): ?CheckoutTry
    {
        return $this->one($this->db->table('sales.checkout_tries')->where('id', strtolower($tryId))->first());
    }

    public function lock(string $tryId): ?CheckoutTry
    {
        return $this->one($this->db->table('sales.checkout_tries')->where('id', strtolower($tryId))->lockForUpdate()->first());
    }

    public function ofOrder(string $orderId): ?CheckoutTry
    {
        return $this->one($this->db->table('sales.checkout_tries')->where('order_id', strtolower($orderId))->first());
    }

    public function liveOfCart(string $cartId): ?CheckoutTry
    {
        return $this->one($this->db->table('sales.checkout_tries')->where('cart_id', $cartId)->where('state', CheckoutTry::LIVE)->first());
    }

    public function keptOfQuote(string $quoteId): ?CheckoutTry
    {
        return $this->one($this->db->table('sales.checkout_tries')->where('quote_id', strtolower($quoteId))->where('state', '<>', CheckoutTry::RELEASED)->first());
    }

    public function end(string $tryId, string $state, DateTimeImmutable $at): void
    {
        $this->db->table('sales.checkout_tries')->where('id', $tryId)->where('state', CheckoutTry::LIVE)
            ->update(['state' => $state, 'ended_at' => Moment::of($at), 'address' => null, 'contact' => null]);
    }

    public function addRefundDue(string $tryId, int $amount): void
    {
        $this->db->update('UPDATE sales.checkout_tries SET refund_due_minor = coalesce(refund_due_minor, 0) + ?, needs_attention = true WHERE id = ?', [$amount, $tryId]);
    }

    public function markForAttention(string $tryId): void
    {
        $this->db->table('sales.checkout_tries')->where('id', $tryId)->update(['needs_attention' => true]);
    }

    public function unseenPaid(string $storeId, string $customerId): ?CheckoutTry
    {
        return $this->one($this->db->table('sales.checkout_tries')->where('customer_id', $customerId)->where('store_id', $storeId)
            ->where('state', CheckoutTry::PAID)->whereNull('seen_at')->orderByDesc('ended_at')->first());
    }

    public function markSeen(string $tryId, DateTimeImmutable $at): bool
    {
        return $this->db->table('sales.checkout_tries')->where('id', $tryId)->whereNull('seen_at')->update(['seen_at' => Moment::of($at)]) === 1;
    }

    public function flagShort(string $storeId, array $orderIds, DateTimeImmutable $at): void
    {
        // In pieces: a statement takes at most 65,535 values.
        foreach (array_chunk($orderIds, 1000) as $some) {
            $this->db->table('sales.checkout_tries')->where('store_id', $storeId)->whereIn('order_id', $some)
                ->where('state', CheckoutTry::LIVE)->whereNull('short_of_stock_at')->update(['short_of_stock_at' => Moment::of($at)]);
        }
    }

    public function livePast(DateTimeImmutable $at, int $most): array
    {
        return array_values(array_map(
            fn (stdClass $row): CheckoutTry => $this->toTry($row),
            $this->db->table('sales.checkout_tries')->where('state', CheckoutTry::LIVE)->where('window_ends_at', '<', Moment::of($at))
                ->orderBy('window_ends_at')->orderBy('id')->limit($most)->get()->all(),
        ));
    }

    private function one(mixed $row): ?CheckoutTry
    {
        return $row instanceof stdClass ? $this->toTry($row) : null;
    }

    private function toTry(stdClass $row): CheckoutTry
    {
        return new CheckoutTry(
            (string) $row->id,
            (string) $row->store_id,
            (string) $row->customer_id,
            (string) $row->cart_id,
            (int) $row->cart_version,
            (string) $row->quote_id,
            (string) $row->order_id,
            (string) $row->attempt_id,
            (string) $row->session_id,
            (string) $row->state,
            (int) $row->amount_minor,
            (string) $row->currency_code,
            CarbonImmutable::parse((string) $row->window_ends_at),
            $row->refund_due_minor === null ? null : (int) $row->refund_due_minor,
            self::copy($row->address),
            self::copy($row->contact),
            $row->short_of_stock_at === null ? null : CarbonImmutable::parse((string) $row->short_of_stock_at),
            (bool) $row->needs_attention,
            CarbonImmutable::parse((string) $row->created_at),
            $row->ended_at === null ? null : CarbonImmutable::parse((string) $row->ended_at),
            $row->seen_at === null ? null : CarbonImmutable::parse((string) $row->seen_at),
        );
    }

    /** @return array<string, mixed>|null */
    private static function copy(mixed $json): ?array
    {
        if ($json === null) {
            return null;
        }

        /** @var array<string, mixed> $copy */
        $copy = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);

        return $copy;
    }
}
