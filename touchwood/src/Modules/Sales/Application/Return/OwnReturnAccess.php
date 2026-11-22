<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Return;

use Modules\Sales\Domain\Exception\ReturnNotFound;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * A customer's own return (sales.md §3): the permission in its store; whose order it is is asked again
 * under the order's lock.
 */
final readonly class OwnReturnAccess
{
    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private ReturnAccess $returns,
    ) {}

    /**
     * @return array{string, StoreId, string} the return, its store, and the customer
     *
     * @throws Unauthorized|ReturnNotFound
     */
    public function authorize(string $permission, string $returnId): array
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            throw new Unauthorized($permission);
        }

        [$id, $store] = $this->returns->found($returnId);
        $this->authorizer->authorize($permission, PermissionScope::store($store));

        return [$id, $store, strtolower($actor->id)];
    }
}
