<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Listing;

use Closure;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * What every change to a store's own rows shares (catalog.md §1.3, §3):
 *
 * 1. **The job in that store**, before anything is read: a store id that is not one is refused as
 *    the permission would be; then the authorizer is asked in that store — someone holding it in
 *    one store never changes another store's row. A store that is switched off is prepared too
 *    (amendment 4(e)); one that does not exist is no store to choose for.
 * 2. **Its own transaction**, retried on a deadlock, **the products' lock first**: a store's choice
 *    reads the product's stage and variants, which a product change writes under the same lock, so a
 *    product archived meanwhile is never switched on.
 * 3. **The audit entries**, recorded in the store, inside the same transaction.
 */
final readonly class StoreListingChange
{
    public function __construct(
        private Authorizer $authorizer,
        private ListLocks $locks,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|Unauthorized
     */
    public function authorize(string $permission, string $storeId): StoreId
    {
        try {
            $store = StoreId::fromString($storeId);
        } catch (InvalidArgumentException) {
            throw new Unauthorized($permission);
        }

        $this->authorizer->authorize($permission, PermissionScope::store($store));

        if ($this->platform->store($store) === null) {
            throw new InvalidCatalogAttribute('store', 'a store');
        }

        return $store;
    }

    /**
     * @template T
     *
     * @param  Closure(): array{T, list<AuditEntryDto>}  $work  answers its result and its audit entries
     * @return T
     */
    public function run(Closure $work): mixed
    {
        return $this->db->transaction(function () use ($work): mixed {
            $this->locks->lock(ListLocks::PRODUCTS);
            [$result, $entries] = $work();

            foreach ($entries as $entry) {
                $this->platform->recordAudit($entry);
            }

            return $result;
        }, 3);
    }
}
