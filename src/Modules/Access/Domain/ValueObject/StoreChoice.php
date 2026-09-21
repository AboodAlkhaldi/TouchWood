<?php

declare(strict_types=1);

namespace Modules\Access\Domain\ValueObject;

use InvalidArgumentException;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Public\Enums\AccessLevel;
use Shared\Domain\ValueObject\StoreId;

/**
 * The stores an action reaches: all stores (now and opened later), or at least one chosen store
 * (handoff §7.5). Whether the chosen stores exist is checked by the handler.
 */
final readonly class StoreChoice
{
    /**
     * @param  list<StoreId>  $stores  sorted, without repeats; empty exactly for all stores
     */
    private function __construct(
        public AccessLevel $level,
        private array $stores,
    ) {}

    public static function allStores(): self
    {
        return new self(AccessLevel::AllStores, []);
    }

    public static function selected(StoreId ...$stores): self
    {
        $byId = [];

        foreach ($stores as $store) {
            $byId[$store->value] = $store;
        }

        if ($byId === []) {
            throw new InvalidAccessAttribute('stores', 'choose at least one store, or all stores');
        }

        ksort($byId);

        return new self(AccessLevel::SelectedStores, array_values($byId));
    }

    /**
     * @param  list<string>  $storeIds  ignored for all stores, required for selected stores
     */
    public static function of(AccessLevel $level, array $storeIds): self
    {
        if ($level === AccessLevel::AllStores) {
            if ($storeIds !== []) {
                throw new InvalidAccessAttribute('stores', 'all stores takes no list of stores');
            }

            return self::allStores();
        }

        $stores = [];

        foreach ($storeIds as $storeId) {
            try {
                $stores[] = StoreId::fromString($storeId);
            } catch (InvalidArgumentException) {
                throw new InvalidAccessAttribute('stores', "\"{$storeId}\" is not a store id");
            }
        }

        return self::selected(...$stores);
    }

    public function isAllStores(): bool
    {
        return $this->level === AccessLevel::AllStores;
    }

    /**
     * @return list<StoreId>|null null for all stores
     */
    public function stores(): ?array
    {
        return $this->isAllStores() ? null : $this->stores;
    }

    /**
     * @return list<string> empty for all stores
     */
    public function storeIds(): array
    {
        return array_map(fn (StoreId $store): string => $store->value, $this->stores);
    }

    public function covers(StoreId $store): bool
    {
        return $this->isAllStores() || in_array($store->value, $this->storeIds(), true);
    }

    /**
     * Whether every store $other reaches is reached by this choice too. Only all stores includes
     * all stores: a list of today's stores does not cover stores opened later.
     */
    public function includes(self $other): bool
    {
        if ($this->isAllStores()) {
            return true;
        }

        if ($other->isAllStores()) {
            return false;
        }

        return array_diff($other->storeIds(), $this->storeIds()) === [];
    }

    public function union(self $other): self
    {
        if ($this->isAllStores() || $other->isAllStores()) {
            return self::allStores();
        }

        return self::selected(...$this->stores, ...$other->stores);
    }

    public function equals(self $other): bool
    {
        return $this->level === $other->level && $this->storeIds() === $other->storeIds();
    }

    /**
     * For audit entries: "all stores" or the store ids.
     *
     * @return list<string>|string
     */
    public function describe(): array|string
    {
        return $this->isAllStores() ? 'all stores' : $this->storeIds();
    }
}
