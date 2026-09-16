<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query;

use Modules\Platform\Public\Dto\CurrencyDto;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * The read side for stores and currencies. Every storefront request resolves its store here,
 * so the implementation must answer from cache without touching the database.
 */
interface StoreDirectory
{
    /**
     * @return list<StoreDto> ordered by position, then code
     */
    public function stores(): array;

    public function storeById(string $id): ?StoreDto;

    public function storeByCode(string $code): ?StoreDto;

    public function currency(string $code): ?CurrencyDto;

    /**
     * Call inside the transaction that changes a store or currency. Readers see the new data
     * once it commits, before any event dispatched after commit reaches its listeners.
     */
    public function invalidate(): void;
}
