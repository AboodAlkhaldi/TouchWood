<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ViewCustomer;

use Modules\Access\Application\Address\AddressMapper;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Query\CustomerReader;
use Modules\Access\Application\Query\ListCustomers\CustomerSummary;
use Modules\Access\Domain\Exception\CustomerNotFound;
use Modules\Access\Domain\Repository\AddressRepository;
use Modules\Access\Public\Dto\AddressDto;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\CustomerStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * One customer, for a staff member who may see them (spec §3.3): the permission is checked in the
 * customer's **home store**, which is the store whose staff see them. A customer of another store
 * is answered as no customer at all — an id tells nobody anything.
 */
final readonly class ViewCustomerHandler
{
    public const string PERMISSION = AccessPermissions::CUSTOMER_VIEW;

    public function __construct(
        private Authorizer $authorizer,
        private CustomerReader $customers,
        private AddressRepository $addresses,
        private AddressMapper $mapper,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws CustomerNotFound
     */
    public function handle(ViewCustomer $query): CustomerDetails
    {
        $stores = $this->authorizer->storesWith(self::PERMISSION);
        $row = $stores === [] ? null : $this->customers->customer($query->customerId);

        // A customer of a store this reader does not see is answered as no customer at all: an id
        // tells nobody anything, here as everywhere else.
        if ($row === null || ! $this->covers($stores, (string) $row['home_store_id'])) {
            throw new CustomerNotFound($query->customerId);
        }

        $summary = new CustomerSummary(
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

        return new CustomerDetails(
            $summary,
            (string) $row['locale'],
            (string) $row['last_store_id'],
            $this->addressesOf((string) $row['id']),
        );
    }

    /**
     * @param  list<StoreId>|null  $stores  null: every store, now and for one opened later
     */
    private function covers(?array $stores, string $homeStoreId): bool
    {
        if ($stores === null) {
            return true;
        }

        foreach ($stores as $store) {
            if ($store->value === $homeStoreId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Their address book in every store, so support can answer "where is my order going?" whichever
     * country it was ordered from.
     *
     * @return list<AddressDto>
     */
    private function addressesOf(string $customerId): array
    {
        $addresses = [];

        foreach ($this->platform->stores() as $store) {
            foreach ($this->addresses->forCustomerInStore($customerId, $store->id) as $address) {
                $addresses[] = $this->mapper->toDto($address);
            }
        }

        return $addresses;
    }
}
