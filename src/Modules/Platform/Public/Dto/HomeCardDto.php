<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

/**
 * One card of the admin home, declared by the module whose figures it shows (platform.md §2.6), as
 * a menu entry is (MenuEntryDto). Platform keeps the list; every module, Platform included, adds
 * its own.
 *
 * What a card shows is not protection: every screen it links to asserts its own permission.
 */
final readonly class HomeCardDto
{
    /**
     * @param  string  $module  the module whose figures it shows, e.g. "b2b"
     * @param  string  $key  unique within that module; its words are read from `{module}::home.{key}`
     * @param  string|list<string>  $permission  per-store actions, any one of which is enough - held
     *                                           in the store chosen on Home for one store, for
     *                                           every store for All Stores
     * @param  string  $card  the class of a HomeCard, which works out what the card shows
     * @param  int  $position  where it sits among the cards, lowest first
     * @param  list<string>  $storeFree  store-free actions that show the card too, in either scope;
     *                                   held at all is held everywhere, so they never offer All Stores
     *                                   to someone whose stores are fewer
     */
    public function __construct(
        public string $module,
        public string $key,
        public string|array $permission,
        public string $card,
        public int $position = 0,
        public array $storeFree = [],
    ) {}

    /**
     * The per-store actions.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return is_string($this->permission) ? [$this->permission] : $this->permission;
    }

    public function wordsKey(): string
    {
        return "{$this->module}::home.{$this->key}";
    }
}
