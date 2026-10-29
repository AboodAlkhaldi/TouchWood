<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\ValueObject;

use Shared\Domain\ValueObject\StoreId;

/**
 * Whose bell is read, and over which stores (ops.md §1.2): a customer's in the shop's store, a staff
 * member's over the stores they may view. Own only: nothing here names anyone but the reader.
 */
final readonly class InboxOwner
{
    /**
     * @param  list<StoreId>  $stores
     */
    private function __construct(
        public ?string $customerId,
        public ?string $staffId,
        public array $stores,
    ) {}

    public static function customer(string $customerId, StoreId $store): self
    {
        return new self(strtolower($customerId), null, [$store]);
    }

    /**
     * @param  list<StoreId>  $stores
     */
    public static function staff(string $staffId, array $stores): self
    {
        return new self(null, strtolower($staffId), $stores);
    }

    /** @return list<string> */
    public function storeIds(): array
    {
        return array_map(static fn (StoreId $store): string => $store->value, $this->stores);
    }
}
