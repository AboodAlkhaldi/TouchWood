<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Menu;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Platform\Public\Contracts\AdminMenu;
use Modules\Platform\Public\Contracts\MenuCount;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * The admin menu, collected from the modules' service providers at boot (stage 2b, P6), the way
 * settings and media usages already are.
 *
 * Platform keeps it because it sits below every module and can therefore serve them all. It never
 * reaches into Access to know who may see what: it asks the Shared `Authorizer`, which Access
 * implements.
 *
 * What is offered is not what is allowed. Every screen behind an entry still asserts its own
 * permission in its handler — hiding a link is not protection (handoff §19).
 */
final class InMemoryAdminMenu implements AdminMenu
{
    /**
     * The order the groups appear in. It is the role editor's list (stage 2b, P2), so the menu and
     * the editor never disagree; a group nothing registers under simply never appears.
     */
    private const array GROUPS = [
        'catalog',
        'pricing',
        'orders',
        'companies',
        'customers',
        'staff_and_permissions',
        'store_settings',
        'media',
        'audit',
        'system',
    ];

    /** @var list<MenuEntryDto> */
    private array $entries = [];

    /**
     * The container, not the Authorizer: this list is registered at boot and lives for the whole
     * application, while the Authorizer is bound **scoped**, to the person acting now. Holding one
     * would answer a later request with an earlier person's menu (the project's own rule: anything
     * that depends on the acting user is never held by a singleton).
     */
    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(MenuEntryDto ...$entries): void
    {
        foreach ($entries as $entry) {
            if (! in_array($entry->group, self::GROUPS, true)) {
                throw new LogicException("The menu entry \"{$entry->module}.{$entry->key}\" is in \"{$entry->group}\", which is not a business area the role editor uses.");
            }

            // A list names the permissions any one of which offers the entry; an empty one would
            // offer it to nobody while looking as if it were guarded (b2b.md amendment 23(a)).
            if (is_array($entry->permission) && ($entry->permission === [] || in_array('', $entry->permission, true))) {
                throw new LogicException("The menu entry \"{$entry->module}.{$entry->key}\" names its permissions as a list, which must hold at least one permission and nothing else.");
            }

            if ($entry->count !== null && ! is_subclass_of($entry->count, MenuCount::class)) {
                throw new LogicException("The menu entry \"{$entry->module}.{$entry->key}\" counts with \"{$entry->count}\", which does not implement ".MenuCount::class.'.');
            }

            foreach ($this->entries as $registered) {
                if ($registered->module === $entry->module && $registered->key === $entry->key) {
                    throw new LogicException("The menu entry \"{$entry->module}.{$entry->key}\" is registered twice.");
                }

                if ($registered->routeName === $entry->routeName) {
                    throw new LogicException("The route \"{$entry->routeName}\" already has a menu entry, \"{$registered->module}.{$registered->key}\".");
                }
            }

            $this->entries[] = $entry;
        }
    }

    public function forCurrentActor(?string $storeWorkedIn = null): array
    {
        // Asked once, not per entry: a Super Admin is offered the entries of modules not built yet,
        // whose permissions cannot be checked because they do not exist (§2.2).
        $authorizer = $this->container->make(Authorizer::class);
        $unlimited = $authorizer->isUnlimited();
        $menu = [];

        foreach (self::GROUPS as $group) {
            $offered = array_values(array_filter(
                $this->entries,
                fn (MenuEntryDto $entry): bool => $entry->group === $group && $this->mayUse($authorizer, $entry, $unlimited, $storeWorkedIn),
            ));

            if ($offered === []) {
                continue;
            }

            usort($offered, fn (MenuEntryDto $a, MenuEntryDto $b): int => [$a->position, $a->key] <=> [$b->position, $b->key]);
            $menu[$group] = $offered;
        }

        return $menu;
    }

    public function countOf(MenuEntryDto $entry): ?int
    {
        if ($entry->count === null) {
            return null;
        }

        $counter = $this->container->make($entry->count);

        // Checked when the entry was registered; resolved from the container, so asked again.
        if (! $counter instanceof MenuCount) {
            throw new LogicException("\"{$entry->count}\" does not implement ".MenuCount::class.'.');
        }

        return $counter->count();
    }

    private function mayUse(Authorizer $authorizer, MenuEntryDto $entry, bool $unlimited, ?string $storeWorkedIn): bool
    {
        $permissions = $entry->permissions();

        // No permission means the module is not built yet - the entry is "coming soon", and only
        // someone unlimited is shown it.
        if ($permissions === []) {
            return $unlimited;
        }

        // One permission: held anywhere, and the screen decides what is in it store by store. Several,
        // any one of which is enough: held in the store being worked in, for a page serving several
        // jobs for that store alone, which would otherwise answer "not allowed" (platform.md §9.4;
        // b2b.md amendment 23(a)).
        foreach ($permissions as $permission) {
            $stores = $authorizer->storesWith($permission);
            $held = $entry->inStoreWorkedIn() ? self::holdsIn($stores, $storeWorkedIn) : $stores !== [];

            if ($held) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<StoreId>|null  $stores  null for every store
     */
    private static function holdsIn(?array $stores, ?string $storeWorkedIn): bool
    {
        if ($storeWorkedIn === null) {
            return false;
        }

        if ($stores === null) {
            return true;
        }

        foreach ($stores as $store) {
            if ($store->value === $storeWorkedIn) {
                return true;
            }
        }

        return false;
    }
}
