<?php

namespace Modules\Platform\Application;

use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Dto\CurrencyDto;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Domain\ValueObject\StoreId;

final readonly class PlatformApiImpl implements PlatformApi
{
    public function __construct(
        private StoreDirectory $directory,
        private AuditLog $auditLog,
    ) {}

    public function store(StoreId $id): ?StoreDto
    {
        return $this->directory->storeById($id->value);
    }

    public function storeByCode(string $code): ?StoreDto
    {
        return $this->directory->storeByCode($code);
    }

    public function stores(): array
    {
        return $this->directory->stores();
    }

    public function currency(string $code): ?CurrencyDto
    {
        return $this->directory->currency($code);
    }

    public function recordAudit(AuditEntryDto $entry): void
    {
        $this->auditLog->record($entry);
    }
}
