<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Repository;

use Modules\Ops\Domain\Model\Subscriber;
use Shared\Domain\ValueObject\StoreId;

/**
 * The newsletter's lists (ops.md §1.9, §5: `ops.subscribers`). The `lock*` reads lock the row until
 * the transaction ends, so a confirmation, a removal and the provider's sync never cross.
 */
interface Subscribers
{
    public function find(string $id): ?Subscriber;

    public function lock(string $id): ?Subscriber;

    public function lockByEmail(StoreId $store, string $email): ?Subscriber;

    public function lockByTokenHash(string $tokenHash): ?Subscriber;

    public function lockByCustomer(string $customerId): ?Subscriber;

    /**
     * Every row of that email, in every store.
     *
     * @return list<Subscriber>
     */
    public function withEmail(string $email): array;

    /** Adds the row, or writes it over the one of the same id. */
    public function save(Subscriber $subscriber): void;

    public function delete(string $id): void;

    /**
     * The store's list, by id, after the one named.
     *
     * @return list<Subscriber>
     */
    public function page(StoreId $store, ?string $afterId, int $limit): array;
}
