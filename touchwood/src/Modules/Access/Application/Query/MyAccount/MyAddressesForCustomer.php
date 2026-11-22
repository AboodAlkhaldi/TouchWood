<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\MyAccount;

use Modules\Access\Application\Address\AddressMapper;
use Modules\Access\Application\Customer\CurrentCustomer;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Domain\Model\Address;
use Modules\Access\Domain\Model\StoreAddressFormat;
use Modules\Access\Domain\Repository\AddressRepository;
use Modules\Access\Domain\Repository\StoreAddressFormatRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * A customer's address book (frontend.md §3.6, F9).
 *
 * **Every store, not only the ones they have an address in.** A customer may shop in any of our
 * countries whichever one they registered in (access.md §1.1), so the book is the list of places
 * they could order from, each with what it has of theirs - which is how "add one here" is offered
 * at all.
 *
 * It answers about the person asking and nobody else: the id comes from who is signed in.
 */
final readonly class MyAddressesForCustomer
{
    public const string PERMISSION = AccessPermissions::ADDRESS_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCustomer $current,
        private AddressRepository $addresses,
        private AddressMapper $mapper,
        private StoreAddressFormatRepository $formats,
        private CustomerSecuritySettings $settings,
        private PlatformApi $platform,
    ) {}

    /**
     * @return list<MyAddressesInStoreDto> in Platform's own store order
     */
    public function forCurrentCustomer(): array
    {
        // Per store, never globally: managing addresses is a permission that belongs to a store,
        // and the book is every store at once (the authorizer refuses a global check outright).
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::allStores());
        $customerId = $this->current->id(self::PERMISSION);

        // One read for every store, grouped here: three queries for three stores would be three
        // round trips for a page that always shows all of them.
        $mine = [];

        foreach ($this->addresses->forCustomer($customerId) as $address) {
            $mine[$address->storeId()][] = $address;
        }

        return array_map(
            fn (StoreDto $store): MyAddressesInStoreDto => $this->inStore($store, $mine[$store->storeId()->value] ?? []),
            $this->platform->stores(),
        );
    }

    /**
     * @param  list<Address>  $addresses
     */
    private function inStore(StoreDto $store, array $addresses): MyAddressesInStoreDto
    {
        $storeId = $store->storeId()->value;
        $format = $this->formats->forStore($storeId);

        return new MyAddressesInStoreDto(
            storeId: $storeId,
            storeCode: $store->code,
            // A store with no format takes no addresses at all (§1.9). Saying so is the whole
            // point: a form offered and then refused teaches nobody anything.
            hasFormat: $format !== null,
            fields: $format === null ? [] : $this->fields($format),
            addresses: $this->mapper->toDtos($addresses),
            limit: $this->settings->addressesPerStore($storeId),
        );
    }

    /**
     * @return list<AddressFieldDto>
     */
    private function fields(StoreAddressFormat $format): array
    {
        return array_map(fn ($field): AddressFieldDto => new AddressFieldDto(
            $field->key,
            $field->labelAr,
            $field->labelEn,
            $field->required,
            $field->maxLength,
        ), $format->fields);
    }
}
