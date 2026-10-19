<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Provider;

use DateTimeImmutable;
use Modules\Sync\Domain\Exception\ConnectionFailed;
use Modules\Sync\Domain\Model\Connection;
use Modules\Sync\Domain\ValueObject\ProviderValue;

/**
 * One provider's way of being read (sync.md §2.1) - Odoo the first. Only Sync's queued reads call
 * `read`; `test` is the one call made in a web request, the Super Admin's save, and gives up after 10
 * seconds (sync.md §9.3 #21).
 */
interface ProviderAdapter
{
    /**
     * @return list<string> the fields this provider asks for beside the address, the key and the secret
     */
    public function fields(): array;

    /**
     * Whether this provider's connection is built - false until it can read: the Provider page says so
     * above its form, and its test refuses with its reason (sync.md §4.1 Y3, Q38).
     */
    public function ready(): bool;

    /**
     * One small read, to know the connection works.
     *
     * @throws ConnectionFailed naming the reason - never the key or the secret
     */
    public function test(Connection $connection): void;

    /**
     * The provider's sizes, page by page: those codes only, or every one; changed since a time, or all.
     *
     * @param  list<string>|null  $codes
     * @return iterable<ProviderValue>
     *
     * @throws ConnectionFailed
     */
    public function read(Connection $connection, ?array $codes, ?DateTimeImmutable $changedSince): iterable;
}
