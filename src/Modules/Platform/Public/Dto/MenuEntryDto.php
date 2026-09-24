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
     * @param  string|null  $permission  the action a person needs to be offered it. **Null means a
     *                                   "coming soon" entry**, for a module whose permissions do not
     *                                   exist yet: it is shown to Super Admins only (§2.2)
     * @param  int  $position  where it sits among the entries of its group, lowest first
     * @param  string|null  $icon  the entry's icon, named from the panel's own short list (see
     *                             `MenuIcon` in the frontend). The sidebar collapses to a rail of
     *                             icons, so an entry without one is a blank square on that rail;
     *                             an unknown name falls back rather than breaking the page
     */
    public function __construct(
        public string $module,
        public string $key,
        public string $group,
        public string $routeName,
        public ?string $permission = null,
        public int $position = 0,
        public ?string $icon = null,
    ) {}

    public function labelKey(): string
    {
        return "{$this->module}::menu.{$this->key}";
    }

    public function comingSoon(): bool
    {
        return $this->permission === null;
    }
}
