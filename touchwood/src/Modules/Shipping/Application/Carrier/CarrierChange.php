<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Carrier;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Shipping\Application\Uncovered\UncoveredRecounts;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * A change to one store's carriers (shipping.md §1.3, §3): staff holding the permission in that
 * store; then the store's carriers' lock, the change and its audit in one transaction.
 */
final readonly class CarrierChange
{
    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private PlatformApi $platform,
        private CarrierRepository $carriers,
        private ConnectionInterface $db,
        private UncoveredRecounts $recounts,
    ) {}

    /**
     * @return StoreDto the store, once the person acting may change its carriers
     *
     * @throws Unauthorized|InvalidShippingInput
     */
    public function authorize(string $permission, string $storeId): StoreDto
    {
        $id = strtolower(trim($storeId));

        if (! Ulids::valid($id)) {
            throw new InvalidShippingInput('store', 'a store');
        }

        $store = StoreId::fromString($id);
        $this->authorizer->authorize($permission, PermissionScope::store($store));

        if ($this->actors->current()->type !== ActorType::Staff) {
            throw new Unauthorized($permission);
        }

        return $this->platform->store($store) ?? throw new InvalidShippingInput('store', 'a store');
    }

    /**
     * @template T
     *
     * @param  Closure(): array{T, list<AuditEntryDto>}  $work
     * @return T
     */
    public function run(string $storeId, Closure $work): mixed
    {
        return $this->db->transaction(function () use ($storeId, $work): mixed {
            $this->carriers->lockStore($storeId);
            [$result, $entries] = $work();

            foreach ($entries as $entry) {
                $this->platform->recordAudit($entry);
            }

            // A change of the store's carriers or prices may move its "Places no carrier serves" (§1.6).
            if ($entries !== []) {
                $this->recounts->queue(StoreId::fromString($storeId));
            }

            return $result;
        }, 3);
    }
}
