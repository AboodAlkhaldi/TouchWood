<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\DeleteAddress;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\AddressAudit;
use Modules\Access\Application\Customer\CurrentCustomer;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\AddressNotFound;
use Modules\Access\Domain\Repository\AddressRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Domain\ValueObject\StoreId;

/**
 * Deleting an address (spec §1.9). When it was the store's default, the newest address left becomes
 * the default, so a store that still has addresses always has one (amendment 41).
 */
final readonly class DeleteAddressHandler
{
    public const string PERMISSION = AccessPermissions::ADDRESS_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCustomer $current,
        private AddressRepository $addresses,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /**
     * @throws AddressNotFound
     */
    public function handle(DeleteAddress $command): void
    {
        $customerId = $this->current->id();

        $this->db->transaction(function () use ($command, $customerId): void {
            $address = $this->addresses->byId($command->addressId);

            if ($address === null || ! $address->belongsTo($customerId)) {
                throw new AddressNotFound($command->addressId);
            }

            // The store of the address, not of the page they are on (spec §1.9).
            $this->authorizer->authorize(self::PERMISSION, PermissionScope::store(StoreId::fromString($address->storeId())));

            $this->addresses->delete($address->id());
            $this->platform->recordAudit(AddressAudit::deleted($address));

            if (! $address->isDefault()) {
                return;
            }

            $next = $this->addresses->newestInStore($customerId, $address->storeId(), $address->id());

            if ($next === null) {
                return;
            }

            $next->makeDefault();
            $this->addresses->update($next);
            $this->platform->recordAudit(AddressAudit::updated($next, $next->pullChanges()));
        }, 3);
    }
}
