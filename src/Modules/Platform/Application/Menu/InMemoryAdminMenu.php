<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Menu;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Platform\Public\Contracts\AdminMenu;
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

    private function mayUse(Authorizer $authorizer, MenuEntryDto $entry, bool $unlimited): bool
    {
        if ($entry->comingSoon()) {
            return $unlimited;
        }

        // Held anywhere is enough to be offered the screen; the screen itself decides what is in it
        // for this person, store by store.
        return $authorizer->storesWith($entry->permission ?? '') !== [];
    }
}
