<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Dto\CurrencyDto;
use Modules\Platform\Public\Dto\SettingValueDto;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/**
 * What other modules may ask Platform (Platform spec §2.1). Media methods are added when media
 * is built.
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
     * The stored value, or the declared default. Served from cache.
     *
     * @param  StoreId|null  $store  required for a per-store setting, forbidden for a global one
     *
     * @throws DomainError for an undeclared key or the wrong scope
     */
    public function setting(string $key, ?StoreId $store = null): SettingValueDto;

    /**
     * Call inside the transaction of the change being audited, after checking permission.
     */
    public function recordAudit(AuditEntryDto $entry): void;
}
