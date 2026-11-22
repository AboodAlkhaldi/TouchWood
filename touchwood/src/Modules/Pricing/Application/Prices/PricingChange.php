<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Closure;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\PlatformPermissions;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\StoreOff;
use Modules\Pricing\Domain\Repository\PricingLocks;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * What every price change in a store shares (pricing.md §3):
 *
 * 1. **The permission in that store**, before anything is looked up - a malformed store id is no
 *    store the actor holds anything in.
 * 2. **A store that exists**: one that does not is invalid input (owner, 2026-10-10).
 * 3. **A store that is off** is changed only by whoever may switch stores (§1.1 rule 8).
 * 4. **Its own transaction**, retried on a deadlock, **the store's lock first**, and the audit entries
 *    the work answers - none when nothing changed - inside it.
 */
final readonly class PricingChange
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private PricingLocks $locks,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput|StoreOff
     */
    public function authorize(string $permission, string $storeId): StoreDto
    {
        try {
            $store = StoreId::fromString($storeId);
        } catch (InvalidArgumentException) {
            throw new Unauthorized($permission);
        }

        $this->authorizer->authorize($permission, PermissionScope::store($store));
        $found = $this->platform->store($store) ?? throw new InvalidPricingInput('store');

        if (! $found->isActive && $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) === []) {
            throw new StoreOff;
        }

        return $found;
    }

    /**
     * @template T
     *
     * @param  Closure(): array{T, list<AuditEntryDto>}  $work  answers its result and its audit entries
     * @return T
     */
    public function run(StoreId $store, Closure $work): mixed
    {
        return $this->db->transaction(function () use ($store, $work): mixed {
            $this->locks->lockStore($store);
            [$result, $entries] = $work();

            foreach ($entries as $entry) {
                $this->platform->recordAudit($entry);
            }

            return $result;
        }, 3);
    }
}
