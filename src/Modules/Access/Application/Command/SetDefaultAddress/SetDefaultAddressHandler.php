<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SetDefaultAddress;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\AddressAudit;
use Modules\Access\Application\Customer\CurrentCustomer;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\AddressNotFound;
use Modules\Access\Domain\Exception\CustomerNotFound;
use Modules\Access\Domain\Repository\AddressRepository;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Domain\ValueObject\StoreId;

/**
 * Choosing the default address of one store (spec §1.9): the others in that store give the flag up
 * in the same transaction, so a store never shows two defaults. Another store's default is
 * untouched — each country has its own.
 */
final readonly class SetDefaultAddressHandler
{
    public const string PERMISSION = AccessPermissions::ADDRESS_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCustomer $current,
        private CustomerRepository $customers,
        private AddressRepository $addresses,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /**
     * @throws AddressNotFound
     */
    public function handle(SetDefaultAddress $command): void
    {
        $customerId = $this->current->id(self::PERMISSION);

        $this->db->transaction(function () use ($command, $customerId): void {
            // Their own row, held for this transaction: two tabs choosing different defaults would
            // otherwise leave the store with two (review of step 5).
            $this->customers->byId($customerId) ?? throw new CustomerNotFound($customerId);
            $address = $this->addresses->byId($command->addressId);

            if ($address === null || ! $address->belongsTo($customerId)) {
                throw new AddressNotFound($command->addressId);
            }

            $this->authorizer->authorize(self::PERMISSION, PermissionScope::store(StoreId::fromString($address->storeId())));
            $address->makeDefault();
            $changed = $address->pullChanges();

            if ($changed === []) {
                return;
            }

            // The others first: one default per store is a unique index, and two rows holding the
            // flag for an instant would break it.
            $this->addresses->clearDefault($customerId, $address->storeId(), $address->id());
            $this->addresses->update($address);
            $this->platform->recordAudit(AddressAudit::updated($address, $changed));
        }, 3);
    }
}
