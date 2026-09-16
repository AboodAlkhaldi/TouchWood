<?php

namespace Modules\Platform\Public\Contracts;

use Modules\Platform\Public\Dto\CurrencyDto;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * What other modules may ask Platform (Platform spec §2.1). Settings, media and audit
 * methods are added as those parts of the module are built.
 */
interface PlatformApi
{
    /**
     * Served from cache: resolving a store costs no queries once warm.
     */
    public function store(StoreId $id): ?StoreDto;

    public function storeByCode(string $code): ?StoreDto;

    /**
     * @return list<StoreDto> ordered by position
     */
    public function stores(): array;

    public function currency(string $code): ?CurrencyDto;
}
