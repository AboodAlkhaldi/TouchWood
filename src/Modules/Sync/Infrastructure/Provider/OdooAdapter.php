<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Provider;

use DateTimeImmutable;
use Modules\Sync\Domain\Exception\ConnectionFailed;
use Modules\Sync\Domain\Model\Connection;
use Modules\Sync\Domain\Provider\ProviderAdapter;

/**
 * Odoo - waiting for its team's answers on the shape of their API (sync.md §9.1; the request:
 * Formats A and B). Until then it calls nothing and every test fails, so no store can be wired to it.
 */
final readonly class OdooAdapter implements ProviderAdapter
{
    public function fields(): array
    {
        return [];
    }

    /** Not until the API of §2.4 is built and its adapter written (Q38). */
    public function ready(): bool
    {
        return false;
    }

    public function test(Connection $connection): void
    {
        throw new ConnectionFailed('The Odoo adapter waits for the provider team\'s documentation.');
    }

    public function read(Connection $connection, ?array $codes, ?DateTimeImmutable $changedSince): iterable
    {
        throw new ConnectionFailed('The Odoo adapter waits for the provider team\'s documentation.');
    }
}
