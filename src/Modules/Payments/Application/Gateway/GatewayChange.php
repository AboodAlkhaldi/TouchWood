<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Domain\Exception\InvalidPaymentsInput;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * A look at or a change to one store's gateway (payments.md §1.2, §3): a Super Admin - the permission is
 * reserved - in that store; then the gateway's lock, the change and its audit in one transaction.
 */
final readonly class GatewayChange
{
    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private PlatformApi $platform,
        private Gateways $gateways,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return array{StoreDto, string} the store, and the staff member acting
     *
     * @throws Unauthorized|InvalidPaymentsInput
     */
    public function authorize(string $storeId): array
    {
        $id = strtolower(trim($storeId));

        if (! Ulids::valid($id)) {
            throw new InvalidPaymentsInput('store', 'a store');
        }

        $store = StoreId::fromString($id);
        $this->authorizer->authorize(PaymentsPermissions::GATEWAY_MANAGE, PermissionScope::store($store));
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Staff || $actor->id === null) {
            throw new Unauthorized(PaymentsPermissions::GATEWAY_MANAGE);
        }

        return [$this->platform->store($store) ?? throw new InvalidPaymentsInput('store', 'a store'), $actor->id];
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
            $this->gateways->lockStore($storeId);
            [$result, $entries] = $work();

            foreach ($entries as $entry) {
                $this->platform->recordAudit($entry);
            }

            return $result;
        }, 3);
    }
}
