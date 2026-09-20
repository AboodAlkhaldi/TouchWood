<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ListCustomers;

use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Query\CustomerReader;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\CustomerStatus;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * The customers of the reader's own stores (spec §3.3): an admin of one store sees the customers
 * whose home store is that one, a multi-store admin theirs, a Super Admin everyone. A customer who
 * ordered in another store appears there through the order, not in that store's customer list.
 */
final readonly class ListCustomersHandler
{
    public const string PERMISSION = AccessPermissions::CUSTOMER_VIEW;

    private const int PER_PAGE_MAX = 100;

    private const int PAGE_MAX = 100_000;

    public function __construct(
        private Authorizer $authorizer,
        private CustomerReader $customers,
    ) {}

    /**
     * @throws Unauthorized when they hold the permission in no store at all
     */
    public function handle(ListCustomers $query): CustomerPage
    {
        $stores = $this->authorizer->storesWith(self::PERMISSION);

        if ($stores === []) {
            throw new Unauthorized(self::PERMISSION);
        }

        $perPage = min(max($query->perPage, 1), self::PER_PAGE_MAX);
        $page = min(max($query->page, 1), self::PAGE_MAX);
        // A caller's value, so it is answered, not thrown at: from() would be a raw ValueError.
        $status = $query->status === null
            ? null
            : (CustomerStatus::tryFrom($query->status) ?? throw new InvalidAccessAttribute('status', 'not an account status'))->value;
        // null is what storesWith() answers for every store, now and for one opened later.
        $storeIds = $stores === null ? null : array_map(static fn (StoreId $store): string => $store->value, $stores);

        $found = $this->customers->customers($storeIds, $query->search, $status, $page, $perPage);

        return new CustomerPage(
            array_map($this->toSummary(...), $found['rows']),
            $found['total'],
            $page,
            $perPage,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toSummary(array $row): CustomerSummary
    {
        return new CustomerSummary(
            (string) $row['id'],
            (string) $row['first_name'],
            (string) $row['last_name'],
            (string) $row['email'],
            $row['phone'] === null ? null : (string) $row['phone'],
            CustomerStatus::from((string) $row['status']),
            AccountType::from((string) $row['account_type']),
            (string) $row['home_store_id'],
            (bool) $row['email_verified'],
            (bool) $row['phone_verified'],
            $row['deletion_scheduled_for'] === null ? null : (string) $row['deletion_scheduled_for'],
            (bool) $row['anonymized'],
            (string) $row['registered_at'],
        );
    }
}
