<?php

declare(strict_types=1);

namespace Modules\Access\Application\Customer;

use Modules\Access\Application\Query\CustomerReader;

/**
 * **A customer read once a request** (frontend.md §5): the shop's frame names whoever is signed in
 * (ShareStorefrontPage) and its parts ask who is looking (CustomerShopperType, for Catalog's menu,
 * catalog.md §4.5) — one read of the same row serves both. Scoped: it lasts one request, never longer,
 * so nothing it holds outlives the request that read it.
 */
final class RequestCustomers
{
    /** @var array<string, array<string, mixed>|null> customer id => the row, or null for none */
    private array $rows = [];

    public function __construct(
        private readonly CustomerReader $customers,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function customer(string $customerId): ?array
    {
        if (! array_key_exists($customerId, $this->rows)) {
            $this->rows[$customerId] = $this->customers->customer($customerId);
        }

        return $this->rows[$customerId];
    }
}
