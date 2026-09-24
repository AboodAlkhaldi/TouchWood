<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query;

use Modules\Platform\Public\Dto\CurrencyDto;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * The read side for stores and currencies. Every storefront request resolves its store here,
 * so the implementation must answer from the cache and never load stores and currencies again.
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
     * Every currency, for the screen that manages them (frontend.md 3.5, E3).
     *
     * @return list<CurrencyDto> ordered by code
     */
    public function currencies(): array;

    /**
     * How many stores charge in each currency, keyed by currency code.
     *
     * A currency any store uses has its exponent locked (platform.md 1.2): changing the number of
     * decimal places under a store would reinterpret every amount ever written in it. The screen
     * shows the lock, and the handler enforces it against the table - this only answers what to
     * draw.
     *
     * @return array<string, int>
     */
    public function storeCountByCurrency(): array;

    /**
     * Call inside the transaction that changes a store or currency. Readers see the new data
     * once it commits, before any event dispatched after commit reaches its listeners.
     */
    public function invalidate(): void;
}
