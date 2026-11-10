<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use InvalidArgumentException;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\StoreOff;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Who may change Content's items, and where (content.md §3, §1.1 rule 7, §7): every use case is in one store and
 * takes its job's permission there; **a store that is off is refused to anyone who may not switch stores**
 * (`StoreOff`); and **an item's id that is unknown, or of a store the person holds the job in nowhere, is the
 * same answer** (`ContentNotFound`), so an id never tells whether it exists elsewhere.
 *
 * The permission is the handler's own (`PERMISSION`): each handler calls `authorize()` with it, as the
 * architecture test asks of every handler.
 */
final readonly class ContentAccess
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private ActorContext $actors,
    ) {}

    /**
     * Before an id is looked up: refuses whoever holds the job in no store at all (lesson 37: the person's right
     * is checked before any id is read).
     *
     * @throws Unauthorized
     */
    public function holds(string $permission): void
    {
        if ($this->authorizer->storesWith($permission) === []) {
            throw new Unauthorized($permission);
        }
    }

    /**
     * The store a use case works in, once the person may: the permission checked there, the store found, and an
     * off store refused to whoever may not switch stores.
     *
     * @param  string|null  $storeId  the store named by the request, or the store of the item found by its id
     * @param  bool  $item  true when the store is an item's, found by the item's id: then an unknown item, or one
     *                      of a store the person does not hold the job in, is not found rather than forbidden
     *
     * @throws Unauthorized|ContentNotFound|StoreOff|InvalidContentInput
     */
    public function authorize(string $permission, ?string $storeId, bool $item = false): StoreDto
    {
        if ($item) {
            $held = $this->authorizer->storesWith($permission);

            if ($storeId === null || ($held !== null && ! in_array(strtolower($storeId), array_map(static fn (StoreId $store): string => $store->value, $held), true))) {
                throw new ContentNotFound;
            }
        }

        try {
            $id = StoreId::fromString((string) $storeId);
        } catch (InvalidArgumentException) {
            // A malformed store is no store the person holds anything in.
            throw new Unauthorized($permission);
        }

        $this->authorizer->authorize($permission, PermissionScope::store($id));
        $store = $this->platform->store($id) ?? throw new InvalidContentInput('store', InvalidContentInput::STORE);

        if (! $store->isActive && $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) === []) {
            throw new StoreOff;
        }

        return $store;
    }

    /** Whether the person holds a permission in this store (asked, never thrown: a campaign's prices, §1.7 #5, #16). */
    public function holdsIn(string $permission, string $storeId): bool
    {
        $held = $this->authorizer->storesWith($permission);

        return $held === null || in_array(strtolower($storeId), array_map(static fn (StoreId $store): string => $store->value, $held), true);
    }

    /** The staff member acting, for `created_by` and `updated_by`; null for the system. */
    public function staffId(): ?string
    {
        $actor = $this->actors->current();

        return $actor->type === ActorType::Staff ? $actor->id : null;
    }
}
