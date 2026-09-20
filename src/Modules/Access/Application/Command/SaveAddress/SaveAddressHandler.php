<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SaveAddress;

use Illuminate\Database\Connection;
use Modules\Access\Application\Address\StoreIds;
use Modules\Access\Application\Audit\AddressAudit;
use Modules\Access\Application\Customer\CurrentCustomer;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Domain\Exception\AddressFormatMissing;
use Modules\Access\Domain\Exception\AddressNotFound;
use Modules\Access\Domain\Exception\CustomerNotFound;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidAddress;
use Modules\Access\Domain\Exception\TooManyAddresses;
use Modules\Access\Domain\Model\Address;
use Modules\Access\Domain\Repository\AddressRepository;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Domain\Repository\StoreAddressFormatRepository;
use Modules\Access\Domain\ValueObject\MapPin;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The customer saves an address in one store (spec §1.9). The store's own format decides which
 * fields there are, which are required and how long they may be; a value whose key that format does
 * not define is refused (amendment 41), so nothing is stored that no screen can show.
 *
 * The first address a customer saves in a store becomes its default. Everything is done inside one
 * transaction that holds the customer's own row, so two tabs cannot both count the last address
 * allowed, or both take the store's default flag.
 */
final readonly class SaveAddressHandler
{
    public const string PERMISSION = AccessPermissions::ADDRESS_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCustomer $current,
        private CustomerRepository $customers,
        private AddressRepository $addresses,
        private StoreAddressFormatRepository $formats,
        private CustomerSecuritySettings $settings,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /**
     * @return string the address's id
     *
     * @throws AddressFormatMissing|AddressNotFound|InvalidAddress|InvalidAccessAttribute|TooManyAddresses
     */
    public function handle(SaveAddress $command): string
    {
        // One shape for the id everywhere after this: the permission, the cache key, the row.
        $store = StoreIds::of($command->storeId);
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));
        $customerId = $this->current->id(self::PERMISSION);
        $storeId = $store->value;

        if ($this->platform->store($store) === null) {
            throw new InvalidAccessAttribute('store', 'unknown');
        }

        $format = $this->formats->forStore($storeId) ?? throw new AddressFormatMissing($storeId);
        $fields = $format->accept($command->fields);
        $phone = PhoneNumber::of($command->phone);
        $pin = MapPin::optional($command->latitude, $command->longitude);
        $limit = $this->settings->addressesPerStore($storeId);

        return $this->db->transaction(function () use ($command, $customerId, $storeId, $fields, $phone, $pin, $limit): string {
            // Their own row, held for this transaction: two tabs saving at once would otherwise
            // both count nine addresses, or both claim the store's default (review of step 5).
            $this->customers->byId($customerId) ?? throw new CustomerNotFound($customerId);

            return $command->addressId === null
                ? $this->create($command, $customerId, $storeId, $fields, $phone, $pin, $limit)
                : $this->edit($command, $customerId, $storeId, $fields, $phone, $pin);
        }, 3);
    }

    /**
     * @param  array<string, string>  $fields
     *
     * @throws TooManyAddresses
     */
    private function create(SaveAddress $command, string $customerId, string $storeId, array $fields, PhoneNumber $phone, ?MapPin $pin, int $limit): string
    {
        $kept = $this->addresses->countForCustomerInStore($customerId, $storeId);

        if ($kept >= $limit) {
            throw new TooManyAddresses($limit);
        }

        $address = Address::add(
            $this->addresses->nextId(), $customerId, $storeId,
            $command->label, $command->recipientName, $phone, $fields, $pin,
            // The first address in a store is its default, so checkout always has one (amendment 41).
            $command->isDefault || $kept === 0,
        );

        // The others give the flag up first: one default per store is a unique index, and two rows
        // holding it for an instant would break it.
        if ($address->isDefault()) {
            $this->addresses->clearDefault($customerId, $storeId, $address->id());
        }

        $this->addresses->add($address);
        $this->platform->recordAudit(AddressAudit::added($address));

        return $address->id();
    }

    /**
     * @param  array<string, string>  $fields
     *
     * @throws AddressNotFound|InvalidAddress
     */
    private function edit(SaveAddress $command, string $customerId, string $storeId, array $fields, PhoneNumber $phone, ?MapPin $pin): string
    {
        $address = $this->addresses->byId((string) $command->addressId);

        // Someone else's address is answered as no address at all: an id tells nobody anything.
        if ($address === null || ! $address->belongsTo($customerId) || $address->storeId() !== $storeId) {
            throw new AddressNotFound((string) $command->addressId);
        }

        $address->change($command->label, $command->recipientName, $phone, $fields, $pin);

        if ($command->isDefault) {
            $address->makeDefault();
        }

        $changed = $address->pullChanges();

        if ($changed !== []) {
            if (in_array('is_default', $changed, true)) {
                $this->addresses->clearDefault($customerId, $storeId, $address->id());
            }

            $this->addresses->update($address);
            $this->platform->recordAudit(AddressAudit::updated($address, $changed));
        }

        return $address->id();
    }
}
