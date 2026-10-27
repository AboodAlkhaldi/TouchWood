<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use LogicException;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Sales\Domain\Model\Cart;
use Modules\Sales\Domain\Model\CartLine;
use Modules\Sales\Domain\Model\RemovedLine;
use Modules\Sales\Domain\Repository\Carts;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/** `Carts` in SQL, no Eloquent model (sales.md §5). Every change moves the cart's `updated_at`. */
final readonly class DatabaseCarts implements Carts
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(StoreId $store, ?string $customerId, ?string $guestId): ?Cart
    {
        $row = $this->buyer($store, $customerId, $guestId)->first();

        return $row instanceof stdClass ? $this->toCart($row) : null;
    }

    public function pieces(StoreId $store, ?string $customerId, ?string $guestId): int
    {
        return (int) $this->buyer($store, $customerId, $guestId)
            ->join('sales.cart_lines as l', 'l.cart_id', '=', 'sales.carts.id')
            ->sum('l.quantity');
    }

    public function lock(StoreId $store, ?string $customerId, ?string $guestId): ?Cart
    {
        $row = $this->buyer($store, $customerId, $guestId)->lockForUpdate()->first();

        return $row instanceof stdClass ? $this->toCart($row) : null;
    }

    public function lockOrCreate(StoreId $store, ?string $customerId, ?string $guestId, DateTimeImmutable $at): Cart
    {
        // The cart that is there, locked: no insert tried, which would wait on its row lock too.
        $existing = $this->lock($store, $customerId, $guestId);

        if ($existing !== null) {
            return $existing;
        }

        // Two first lines at once: one row, the other insert ignored on the buyer's key.
        $this->db->table('sales.carts')->insertOrIgnore([
            'id' => strtolower((string) Str::ulid()),
            'store_id' => $store->value,
            'customer_id' => $customerId,
            'guest_id' => $customerId === null ? $guestId : null,
            'created_at' => Moment::of($at),
            'updated_at' => Moment::of($at),
        ]);

        $row = $this->buyer($store, $customerId, $guestId)->lockForUpdate()->first();

        return $row instanceof stdClass ? $this->toCart($row) : throw new LogicException('A cart just written is not there.');
    }

    public function add(string $cartId, string $variantId, SaleMode $mode, int $quantity, DateTimeImmutable $at, ?DateTimeImmutable $addedAt = null): void
    {
        $this->db->statement(
            'INSERT INTO sales.cart_lines (cart_id, variant_id, sale_mode, quantity, added_at) VALUES (?, ?, ?, ?, ?)'
            .' ON CONFLICT (cart_id, variant_id, sale_mode) DO UPDATE SET quantity = LEAST(sales.cart_lines.quantity + EXCLUDED.quantity, ?)',
            [$cartId, $variantId, $mode->value, min($quantity, CartLine::MOST), Moment::of($addedAt ?? $at), CartLine::MOST],
        );
        $this->touch($cartId, $at);
    }

    public function change(string $cartId, string $variantId, SaleMode $mode, int $quantity, DateTimeImmutable $at): bool
    {
        $changed = $this->line($cartId, $variantId, $mode)->update(['quantity' => $quantity]) === 1;

        if ($changed) {
            $this->touch($cartId, $at);
        }

        return $changed;
    }

    public function remove(string $cartId, string $variantId, SaleMode $mode, DateTimeImmutable $at): void
    {
        if ($this->line($cartId, $variantId, $mode)->delete() > 0) {
            $this->touch($cartId, $at);
        }
    }

    public function setCoupon(string $cartId, ?string $code, DateTimeImmutable $at): void
    {
        // A change ends the notice of what left at sign-in, as every change of the cart does.
        $this->db->table('sales.carts')->where('id', $cartId)->update(['coupon_code' => $code, 'updated_at' => Moment::of($at), 'notice' => null]);
    }

    public function setNotice(string $cartId, array $notice, DateTimeImmutable $at): void
    {
        $this->db->table('sales.carts')->where('id', $cartId)->update([
            'notice' => $notice === [] ? null : json_encode(array_map(static fn (RemovedLine $line): array => [
                'variantId' => $line->variantId, 'mode' => $line->mode->value, 'quantity' => $line->quantity, 'reason' => $line->reason,
            ], $notice), JSON_THROW_ON_ERROR),
            'updated_at' => Moment::of($at),
        ]);
    }

    public function lockGuestCarts(string $guestId): array
    {
        return array_values(array_map(
            fn (stdClass $row): Cart => $this->toCart($row),
            $this->db->table('sales.carts')->where('guest_id', $guestId)->orderBy('store_id')->lockForUpdate()->get()->all(),
        ));
    }

    public function delete(string $cartId): void
    {
        $this->db->table('sales.carts')->where('id', $cartId)->delete();
    }

    public function deleteForCustomer(string $customerId): int
    {
        // Locked first, as a change takes them: a line being added at that moment finishes, then goes too.
        $ids = $this->db->table('sales.carts')->where('customer_id', $customerId)->lockForUpdate()->pluck('id')->all();

        return $ids === [] ? 0 : $this->db->table('sales.carts')->whereIn('id', $ids)->delete();
    }

    public function deleteUntouchedSince(DateTimeImmutable $before): int
    {
        return $this->db->table('sales.carts')->where('updated_at', '<', Moment::of($before))->delete();
    }

    private function buyer(StoreId $store, ?string $customerId, ?string $guestId): Builder
    {
        $query = $this->db->table('sales.carts')->where('store_id', $store->value);

        return $customerId !== null ? $query->where('customer_id', $customerId) : $query->where('guest_id', (string) $guestId);
    }

    private function line(string $cartId, string $variantId, SaleMode $mode): Builder
    {
        return $this->db->table('sales.cart_lines')->where('cart_id', $cartId)->where('variant_id', $variantId)->where('sale_mode', $mode->value);
    }

    private function touch(string $cartId, DateTimeImmutable $at): void
    {
        // A change ends the notice of what left at sign-in (my assumption: it was seen).
        $this->db->table('sales.carts')->where('id', $cartId)->update(['updated_at' => Moment::of($at), 'notice' => null]);
    }

    private function toCart(stdClass $row): Cart
    {
        $lines = array_values(array_map(static fn (stdClass $line): CartLine => new CartLine(
            (string) $line->variant_id,
            SaleMode::from((string) $line->sale_mode),
            (int) $line->quantity,
            CarbonImmutable::parse((string) $line->added_at),
        ), $this->db->table('sales.cart_lines')->where('cart_id', $row->id)->orderBy('added_at')->orderBy('variant_id')->orderBy('sale_mode')->get()->all()));

        /** @var list<array{variantId: string, mode: string, quantity: int, reason: string}> $notice */
        $notice = $row->notice === null ? [] : json_decode((string) $row->notice, true, 512, JSON_THROW_ON_ERROR);

        return new Cart(
            (string) $row->id,
            (string) $row->store_id,
            $row->customer_id === null ? null : (string) $row->customer_id,
            $row->guest_id === null ? null : (string) $row->guest_id,
            $row->coupon_code === null ? null : (string) $row->coupon_code,
            $lines,
            array_map(static fn (array $line): RemovedLine => new RemovedLine($line['variantId'], SaleMode::from($line['mode']), (int) $line['quantity'], $line['reason']), $notice),
            CarbonImmutable::parse((string) $row->updated_at),
        );
    }
}
