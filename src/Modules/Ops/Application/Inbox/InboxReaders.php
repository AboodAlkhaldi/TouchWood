<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Inbox;

use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\ValueObject\InboxOwner;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\StoreContext;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * Who is reading a bell, and over which stores (ops.md §1.2): a customer reads their own entries in the
 * shop's store; a staff member their own over the stores they may view - a Super Admin every store,
 * the ones that are off too (whoever may switch stores, as Platform's StoreChoices answers); anyone
 * else the stores that are on. Nobody else has a bell.
 *
 * Every handler asks `permission()` and authorizes it before `owner()`.
 */
final readonly class InboxReaders
{
    /** A bell's page. */
    public const int PAGE = 20;

    public function __construct(
        private ActorContext $actors,
        private Authorizer $authorizer,
        private StoreContext $stores,
        private PlatformApi $platform,
    ) {}

    /**
     * The permission the reader's bell is read under: every customer's, or every staff member's.
     *
     * @throws Unauthorized for anyone else
     */
    public function permission(): string
    {
        $actor = $this->actors->current();

        return match (true) {
            $actor->id === null => throw new Unauthorized(OpsPermissions::INBOX_VIEW_OWN),
            $actor->type === ActorType::Customer => OpsPermissions::INBOX_VIEW_OWN,
            $actor->type === ActorType::Staff => OpsPermissions::PANEL_INBOX_VIEW_OWN,
            default => throw new Unauthorized(OpsPermissions::INBOX_VIEW_OWN),
        };
    }

    /** @throws Unauthorized */
    public function owner(): InboxOwner
    {
        $actor = $this->actors->current();
        $permission = $this->permission();
        $id = (string) $actor->id;

        if ($permission === OpsPermissions::INBOX_VIEW_OWN) {
            return InboxOwner::customer($id, $this->stores->current());
        }

        $mayOpenOff = $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) !== [];

        return InboxOwner::staff($id, array_values(array_map(
            static fn (StoreDto $store) => $store->storeId(),
            array_filter($this->platform->allStores(), static fn (StoreDto $store): bool => $mayOpenOff || $store->isActive),
        )));
    }

    /**
     * An entry id as a request names it.
     *
     * @throws InvalidOpsInput
     */
    public static function id(?string $id, string $attribute): ?string
    {
        if ($id === null || trim($id) === '') {
            return null;
        }

        $id = strtolower(trim($id));

        return Ulids::valid($id) ? $id : throw new InvalidOpsInput($attribute, 'not an entry of this list');
    }

    /** @return 'ar'|'en' */
    public static function locale(string $locale): string
    {
        return $locale === 'ar' ? 'ar' : 'en';
    }
}
