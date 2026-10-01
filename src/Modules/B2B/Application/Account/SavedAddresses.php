<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Account;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\AddressDto;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * The account's saved addresses, which a company picks its registered address from (b2b.md §1.1,
 * §4.5, amendment 16(f)) — **any store's**, each in its store's format. They are Access's; B2B only
 * reads them, and keeps a copy of the one picked.
 */
final readonly class SavedAddresses
{
    public function __construct(
        private AccessApi $access,
        private PlatformApi $platform,
    ) {}

    /**
     * The saved address picked, as the copy the company and the application keep.
     *
     * Another account's address answers as one that does not exist: nobody learns from a refusal
     * that somebody else has an address there. One its store's format no longer accepts cannot be
     * picked, as it could not be shipped to (access.md amendment 41).
     *
     * @throws InvalidCompanyAttribute
     */
    public function pick(string $customerId, string $addressId): CompanyAddress
    {
        if (trim($addressId) === '') {
            throw new InvalidCompanyAttribute('address', 'required');
        }

        $address = $this->access->address($addressId);

        if ($address === null || strtolower($address->customerId) !== strtolower($customerId)) {
            throw new InvalidCompanyAttribute('address', 'not one of the account\'s saved addresses');
        }

        if (! $address->isComplete) {
            throw new InvalidCompanyAttribute('address', 'no longer accepted by its store\'s format');
        }

        return CompanyAddress::saved($address->id, $address->formatted);
    }

    /**
     * Every saved address of the account, store by store in the stores' own order, each store's
     * default first.
     *
     * @return list<array{store: StoreDto, address: AddressDto}>
     */
    public function of(string $customerId): array
    {
        $all = [];

        foreach ($this->platform->stores() as $store) {
            foreach ($this->access->addresses($customerId, $store->id) as $address) {
                $all[] = ['store' => $store, 'address' => $address];
            }
        }

        return $all;
    }
}
