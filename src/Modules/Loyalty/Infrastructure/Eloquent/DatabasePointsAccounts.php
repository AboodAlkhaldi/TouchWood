<?php

declare(strict_types=1);

namespace Modules\Loyalty\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;
use Modules\Loyalty\Domain\Model\Lot;
use Modules\Loyalty\Domain\Model\PointsAccount;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/**
 * The points accounts and their lots on the query builder (loyalty.md §1.12): no Eloquent model, the
 * store named in every call. The account row is the lock every change of an account takes first;
 * its lots after it, in one order — the date they expire, then their id (lesson 37). Locking and
 * expiring are one call, so no write can lock an account and skip its dead lots (§1.1).
 */
final readonly class DatabasePointsAccounts implements PointsAccounts
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(string $customerId, StoreId $store): ?PointsAccount
    {
        if (! Ulids::valid($customerId)) {
            return null;
        }

        return $this->row(strtolower($customerId), $store, lock: false);
    }

    public function lockOrCreate(string $customerId, StoreId $store, DateTimeImmutable $now): PointsAccount
    {
        // A lock outside a transaction ends with its statement and guards nothing, and the expiry's
        // entries and lots would commit one by one (both kept for ever).
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('A points account is locked inside its change\'s transaction.');
        }

        if (! Ulids::valid($customerId)) {
            throw new LogicException('A points account belongs to a customer\'s ULID.');
        }

        // Ids are stored lowercase; the column compares exactly.
        $customerId = strtolower($customerId);
        $stamp = self::moment(CarbonImmutable::now());

        // Inserted only if absent: two first writes for one customer in one store make one account,
        // and each then waits on the other's lock instead of failing on the unique key (lesson 64).
        $this->db->statement(
            'INSERT INTO loyalty.accounts (id, customer_id, store_id, balance, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?) ON CONFLICT (customer_id, store_id) DO NOTHING',
            [strtolower((string) Str::ulid()), $customerId, $store->value, $stamp, $stamp],
        );

        $account = $this->row($customerId, $store, lock: true)
            ?? throw new LogicException('The points account just made could not be read back.');

        return $this->expireDeadLots($account, $now) > 0
            ? ($this->row($customerId, $store, lock: false) ?? $account)
            : $account;
    }

    public function liveLots(PointsAccount $account, DateTimeImmutable $now): array
    {
        return array_values(array_map(
            self::lot(...),
            $this->db->table('loyalty.lots')
                ->where('account_id', $account->id)
                ->where('points_left', '>', 0)
                ->where('expires_at', '>', self::moment($now))
                ->orderBy('expires_at')
                ->orderBy('id')
                ->get()
                ->all(),
        ));
    }

    public function liveBalance(PointsAccount $account, DateTimeImmutable $now): int
    {
        return (int) $this->db->table('loyalty.lots')
            ->where('account_id', $account->id)
            ->where('points_left', '>', 0)
            ->where('expires_at', '>', self::moment($now))
            ->sum('points_left');
    }

    /**
     * Expires the locked account's lots whose date has passed (§1.10): each loses its remaining points
     * as one EXPIRED entry, dated the moment of the write, with its movement, and the balance falls
     * by them. Answers the points expired; a lot already empty needs nothing.
     */
    private function expireDeadLots(PointsAccount $account, DateTimeImmutable $now): int
    {
        $dead = $this->db->table('loyalty.lots')
            ->where('account_id', $account->id)
            ->where('points_left', '>', 0)
            ->where('expires_at', '<=', self::moment($now))
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all();

        $expired = 0;

        foreach ($dead as $row) {
            /** @var stdClass $row */
            $points = (int) $row->points_left;

            $entry = $this->db->table('loyalty.entries')->insertGetId([
                'account_id' => $account->id,
                'store_id' => $account->store->value,
                'kind' => 'EXPIRED',
                'points' => -$points,
                'occurred_at' => self::moment($now),
            ]);
            $this->db->table('loyalty.lot_movements')->insert([
                'account_id' => $account->id,
                'lot_id' => (int) $row->id,
                'entry_id' => $entry,
                'points' => -$points,
            ]);
            $this->db->table('loyalty.lots')->where('id', (int) $row->id)->update(['points_left' => 0]);

            $expired += $points;
        }

        if ($expired > 0) {
            $this->db->table('loyalty.accounts')
                ->where('id', $account->id)
                ->where('store_id', $account->store->value)
                ->decrement('balance', $expired, ['updated_at' => self::moment($now)]);
        }

        return $expired;
    }

    private function row(string $customerId, StoreId $store, bool $lock): ?PointsAccount
    {
        $query = $this->db->table('loyalty.accounts')
            ->where('customer_id', $customerId)
            ->where('store_id', $store->value);

        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row instanceof stdClass ? self::account($row) : null;
    }

    /**
     * A moment as PostgreSQL reads it: in UTC, with its offset and microseconds. Laravel binds a
     * DateTimeInterface as 'Y-m-d H:i:s', which drops both.
     */
    private static function moment(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    private static function account(stdClass $row): PointsAccount
    {
        return new PointsAccount(
            (string) $row->id,
            (string) $row->customer_id,
            StoreId::fromString((string) $row->store_id),
            (int) $row->balance,
        );
    }

    private static function lot(stdClass $row): Lot
    {
        return new Lot(
            (int) $row->id,
            (string) $row->account_id,
            (int) $row->points,
            (int) $row->points_left,
            new DateTimeImmutable((string) $row->expires_at),
            $row->order_id === null ? null : (string) $row->order_id,
        );
    }
}
