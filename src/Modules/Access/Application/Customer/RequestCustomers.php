<?php

declare(strict_types=1);

namespace Modules\Access\Application\Customer;

use Illuminate\Contracts\Container\Container;
use Modules\Access\Application\Query\CustomerReader;

/**
 * **A customer read once a request** (frontend.md §5): the shop's frame names whoever is signed in
 * (ShareStorefrontPage) and its parts ask who is looking (CustomerShopperType, for Catalog's menu,
 * catalog.md §4.5) — one read of the same row serves both.
 *
 * What it holds belongs to the request that read it, and to no other: a server that keeps the
 * application between requests and does not forget its scoped instances (the browser tests' does)
 * would otherwise show a name saved on the request before (found by StorefrontAccountScreenTest).
 */
final class RequestCustomers
{
    /** @var array<string, array<string, mixed>|null> customer id => the row, or null for none */
    private array $rows = [];

    private ?object $readFor = null;

    public function __construct(
        private readonly CustomerReader $customers,
        private readonly Container $app,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function customer(string $customerId): ?array
    {
        $request = $this->app->bound('request') ? $this->app->make('request') : null;

        if (! is_object($request) || $request !== $this->readFor) {
            $this->rows = [];
            $this->readFor = is_object($request) ? $request : null;
        }

        if (! array_key_exists($customerId, $this->rows)) {
            $this->rows[$customerId] = $this->customers->customer($customerId);
        }

        return $this->rows[$customerId];
    }
}
