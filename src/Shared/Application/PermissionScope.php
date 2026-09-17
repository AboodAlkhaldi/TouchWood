<?php

declare(strict_types=1);

namespace Shared\Application;

use Shared\Domain\ValueObject\StoreId;

/**
 * What a permission check applies to. Three cases, told apart explicitly, because an empty store
 * used to mean both "no store is involved" and "every store is involved" — and Access cannot
 * guess which one a handler meant.
 *
 * - global(): the thing has no store at all (media, currencies, platform settings).
 * - store(): one store's data. A staff member scoped to that store passes.
 * - allStores(): the actor must hold the permission in every store, because the change reaches
 *   all of them (a global setting, a currency used by every store).
 */
final readonly class PermissionScope
{
    private function __construct(
        private ?StoreId $store,
        private bool $allStores,
    ) {}

    public static function global(): self
    {
        return new self(null, false);
    }

    public static function store(StoreId $store): self
    {
        return new self($store, false);
    }

    public static function allStores(): self
    {
        return new self(null, true);
    }

    /**
     * The store this check is about, or null when it is global or all-stores.
     */
    public function storeId(): ?StoreId
    {
        return $this->store;
    }

    public function isGlobal(): bool
    {
        return $this->store === null && ! $this->allStores;
    }

    public function requiresAllStores(): bool
    {
        return $this->allStores;
    }

    /**
     * For audit entries and messages: "global", "all stores", or the store's id.
     */
    public function describe(): string
    {
        return match (true) {
            $this->allStores => 'all stores',
            $this->store === null => 'global',
            default => $this->store->value,
        };
    }
}
