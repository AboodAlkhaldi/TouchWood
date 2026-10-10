<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Menu;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Platform\Public\Contracts\AdminMenu;
use Modules\Platform\Public\Contracts\MenuCount;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Shared\Application\Authorizer;

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
     * The order the groups appear in the menu, handoff §14's tree. The role editor lists the same
     * areas in `PermissionGroup`'s own order (stage 2b, P2), which differs. A group nothing registers
     * under simply never appears.
     */
    private const array GROUPS = [
        'catalog',
        'pricing',
        'orders',
        'companies',
        'customers',
        // Handoff §14 places Points after Customers and before Staff (platform.md §9.4).
        'points',
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

    public function forCurrentActor(): array
    {
        // Asked once, not per entry: a Super Admin is offered the entries of modules not built yet,
        // whose permissions cannot be checked because they do not exist (§2.2).
        $authorizer = $this->container->make(Authorizer::class);
        $unlimited = $authorizer->isUnlimited();
        $menu = [];

        foreach (self::GROUPS as $group) {
            $offered = array_values(array_filter(
                $this->entries,
                fn (MenuEntryDto $entry): bool => $entry->group === $group && $this->mayUse($authorizer, $entry, $unlimited),
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

    private function mayUse(Authorizer $authorizer, MenuEntryDto $entry, bool $unlimited): bool
    {
        $permissions = $entry->permissions();

        // No permission means the module is not built yet - the entry is "coming soon", and only
        // someone unlimited is shown it.
        if ($permissions === []) {
            return $unlimited;
        }

        // Any of its permissions held in any store: the screen then chooses a store where the job is
        // held, with its own filter (platform.md §9.10; the panel has no store worked in since
        // 2026-10-06 - before, an entry of several permissions looked at that store alone, §9.4).
        foreach ($permissions as $permission) {
            if ($authorizer->storesWith($permission) !== []) {
                return true;
            }
        }

        return false;
    }
}
