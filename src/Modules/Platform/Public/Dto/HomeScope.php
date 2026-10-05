<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Shared\Domain\ValueObject\StoreId;

/**
 * Which stores a home card speaks for (platform.md §2.6): the store being worked in, or every store
 * - the admin home's This Store and All Stores (frontend.md §2.2).
 */
final readonly class HomeScope
{
    private function __construct(
        private ?StoreId $store,
    ) {}

    public static function store(StoreId $store): self
    {
        return new self($store);
    }

    public static function allStores(): self
    {
        return new self(null);
    }

    public function isAllStores(): bool
    {
        return $this->store === null;
    }

    /**
     * @return list<StoreId>|null the one store, or null for every store
     */
    public function stores(): ?array
    {
        return $this->store === null ? null : [$this->store];
    }
}
