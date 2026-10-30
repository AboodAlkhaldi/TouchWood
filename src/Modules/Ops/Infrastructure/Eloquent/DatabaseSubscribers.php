<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Domain\Model\Subscriber;
use Modules\Ops\Domain\Repository\Subscribers;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/** `Subscribers` in SQL (ops.md §5: `ops.subscribers`). */
final readonly class DatabaseSubscribers implements Subscribers
{
    private const string COLUMNS = 'id, store_id, email, locale, customer_id, token_hash, created_at, confirmed_at, brevo_email, brevo_locale';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(string $id): ?Subscriber
    {
        return $this->one('id = ?', [strtolower($id)], lock: false);
    }

    public function lock(string $id): ?Subscriber
    {
        return $this->one('id = ?', [strtolower($id)], lock: true);
    }

    public function lockByEmail(StoreId $store, string $email): ?Subscriber
    {
        return $this->one('store_id = ? AND email = ?', [$store->value, $email], lock: true);
    }

    public function lockByTokenHash(string $tokenHash): ?Subscriber
    {
        return $this->one('token_hash = ?', [$tokenHash], lock: true);
    }

    public function lockByCustomer(string $customerId): ?Subscriber
    {
        return $this->one('customer_id = ?', [strtolower($customerId)], lock: true);
    }

    public function withEmail(string $email): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->select('SELECT '.self::COLUMNS.' FROM ops.subscribers WHERE email = ? ORDER BY id', [$email]);

        return array_map(self::subscriber(...), $rows);
    }

    public function save(Subscriber $subscriber): void
    {
        $values = [
            'store_id' => $subscriber->store->value,
            'email' => $subscriber->email,
            'locale' => $subscriber->locale,
            'kind' => $subscriber->isGuest() ? 'GUEST' : 'CUSTOMER',
            'customer_id' => $subscriber->customerId === null ? null : strtolower($subscriber->customerId),
            'token_hash' => $subscriber->tokenHash,
            'created_at' => Moment::of($subscriber->createdAt),
            'confirmed_at' => $subscriber->confirmedAt === null ? null : Moment::of($subscriber->confirmedAt),
            'brevo_email' => $subscriber->brevoEmail,
            'brevo_locale' => $subscriber->brevoLocale,
            'brevo_synced_at' => $subscriber->brevoEmail === null ? null : Moment::of(CarbonImmutable::now()->toDateTimeImmutable()),
        ];

        $this->db->table('ops.subscribers')->updateOrInsert(['id' => strtolower($subscriber->id)], $values);
    }

    public function delete(string $id): void
    {
        $this->db->table('ops.subscribers')->where('id', strtolower($id))->delete();
    }

    public function page(StoreId $store, ?string $afterId, int $limit): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->select(
            'SELECT '.self::COLUMNS.' FROM ops.subscribers WHERE store_id = ?'.($afterId === null ? '' : ' AND id > ?').' ORDER BY id LIMIT ?',
            $afterId === null ? [$store->value, $limit] : [$store->value, strtolower($afterId), $limit],
        );

        return array_map(self::subscriber(...), $rows);
    }

    /** @param  list<string>  $bindings */
    private function one(string $where, array $bindings, bool $lock): ?Subscriber
    {
        $row = $this->db->selectOne('SELECT '.self::COLUMNS." FROM ops.subscribers WHERE {$where}".($lock ? ' FOR UPDATE' : ''), $bindings);

        return $row instanceof stdClass ? self::subscriber($row) : null;
    }

    private static function subscriber(stdClass $row): Subscriber
    {
        return new Subscriber(
            (string) $row->id,
            StoreId::fromString((string) $row->store_id),
            (string) $row->email,
            (string) $row->locale,
            $row->customer_id === null ? null : (string) $row->customer_id,
            $row->token_hash === null ? null : (string) $row->token_hash,
            self::at($row->created_at),
            $row->confirmed_at === null ? null : self::at($row->confirmed_at),
            $row->brevo_email === null ? null : (string) $row->brevo_email,
            $row->brevo_locale === null ? null : (string) $row->brevo_locale,
        );
    }

    private static function at(mixed $value): DateTimeImmutable
    {
        return CarbonImmutable::parse((string) $value)->toDateTimeImmutable();
    }
}
