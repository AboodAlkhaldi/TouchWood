<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Return;

use Modules\Sales\Domain\Exception\ReturnNotFound;
use Modules\Sales\Domain\Repository\Returns;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * Who may change a return (sales.md §3): staff with the permission in its order's store — one of a store
 * they do not cover is no return to them.
 */
final readonly class ReturnAccess
{
    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private Returns $returns,
    ) {}

    /**
     * @return array{string, StoreId, string} the return, its store, and the staff member
     *
     * @throws Unauthorized|ReturnNotFound
     */
    public function authorize(string $permission, string $returnId): array
    {
        $actor = $this->actors->current();
        $covered = $this->authorizer->storesWith($permission);

        if ($actor->type !== ActorType::Staff || $actor->id === null || $covered === []) {
            throw new Unauthorized($permission);
        }

        [$id, $store] = $this->found($returnId);

        if ($covered !== null && ! in_array($store->value, array_map(static fn (StoreId $one): string => $one->value, $covered), true)) {
            throw new ReturnNotFound;
        }

        $this->authorizer->authorize($permission, PermissionScope::store($store));

        return [$id, $store, strtolower($actor->id)];
    }

    /**
     * @return array{string, StoreId}
     *
     * @throws ReturnNotFound
     */
    public function found(string $returnId): array
    {
        $id = strtolower(trim($returnId));
        $found = Ulids::valid($id) ? $this->returns->orderOf($id) : null;

        return $found === null ? throw new ReturnNotFound : [$id, StoreId::fromString($found[1])];
    }
}
