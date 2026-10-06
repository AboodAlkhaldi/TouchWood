<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

/**
 * One entry of the admin menu, declared by the module that owns the screen behind it (stage 2b,
 * P6). Platform keeps the list; every module, Platform included, adds its own.
 *
 * The menu only decides what is **offered**. What is allowed is decided where it always is — the
 * handler behind the screen asserts its permission. Hiding a link is not protection.
 */
final readonly class MenuEntryDto
{
    /**
     * @param  string  $module  the module that owns the screen, e.g. "access"
     * @param  string  $key  unique within that module; its name is read from
     *                       `{module}::menu.{key}` in Arabic and English
     * @param  string  $group  the business area it sits under — the same list the role editor uses
     *                         (owner, 2026-09-22), so the two never disagree
     * @param  string  $routeName  the named route the entry opens
     * @param  string|list<string>|null  $permission  the action a person needs to be offered it -
     *                                                held in any store, the screen deciding what is
     *                                                in it store by store - or **several, any one
     *                                                of which, held in any store**, is enough: a
     *                                                page that serves several jobs, such as B2B's
     *                                                type lists (platform.md §9.4, §9.10; b2b.md
     *                                                amendments 23(a), 30). **Null means a "coming
     *                                                soon" entry**, for a module whose permissions
     *                                                do not exist yet: it is shown to Super Admins
     *                                                only (§2.2). An empty list is refused on
     *                                                register
     * @param  int  $position  where it sits among the entries of its group, lowest first
     * @param  string|null  $icon  the entry's icon, named from the panel's own short list (see
     *                             `MenuIcon` in the frontend). The sidebar's rows carry their
     *                             business area's icon; an entry's own shows in the menu an area's
     *                             icon opens on the collapsed rail. An unknown name, or none, draws
     *                             the fallback rather than breaking the page
     * @param  string|null  $count  the class of a `MenuCount`: how many things wait behind the entry
     *                              — failed jobs, say — shown beside it and on the admin home; asked
     *                              only for people offered the entry
     */
    public function __construct(
        public string $module,
        public string $key,
        public string $group,
        public string $routeName,
        public string|array|null $permission = null,
        public int $position = 0,
        public ?string $icon = null,
        public ?string $count = null,
    ) {}

    public function labelKey(): string
    {
        return "{$this->module}::menu.{$this->key}";
    }

    public function comingSoon(): bool
    {
        return $this->permission === null;
    }

    /**
     * The permissions any one of which offers the entry; empty for a "coming soon" entry.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return match (true) {
            $this->permission === null => [],
            is_string($this->permission) => [$this->permission],
            default => $this->permission,
        };
    }
}
