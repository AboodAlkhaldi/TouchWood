<?php

namespace Modules\Platform\Public\Contracts;

use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Dto\CurrencyDto;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * What other modules may ask Platform (Platform spec §2.1). Settings and media methods are
 * added as those parts of the module are built.
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

    /**
     * Call inside the transaction of the change being audited, after checking permission.
     */
    public function recordAudit(AuditEntryDto $entry): void;
}
