<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query;

/**
 * The rows the staff screens read about customers (spec §3.3). Reads only: nothing here changes an
 * account, and every list is already narrowed to the stores the reader may see.
 */
interface CustomerReader
{
    /**
     * @param  list<string>|null  $homeStoreIds  the stores whose customers may be seen; null: every store
     * @param  string|null  $search  part of a name, an email or a phone number
     * @param  string|null  $accountType  INDIVIDUAL or COMPANY; null is both
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function customers(?array $homeStoreIds, ?string $search, ?string $status, ?string $accountType, int $page, int $perPage): array;

    /**
     * @return array<string, mixed>|null
     */
    public function customer(string $customerId): ?array;
}
