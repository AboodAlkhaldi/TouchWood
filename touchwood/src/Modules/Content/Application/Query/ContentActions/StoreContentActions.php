<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\ContentActions;

/**
 * **What Content's screens may offer in one store** (content.md §3, §4.2): the kinds of item the reader may
 * edit there (ContentPermissions::KINDS' keys, the theme left out: it is every store's), and whether the store
 * is off - offered, marked Off, only to whoever may switch stores. Offering is never allowing: every handler
 * asks again. Each screen's own answers join this with its PR.
 */
final readonly class StoreContentActions
{
    /**
     * @param  list<string>  $kinds
     */
    public function __construct(
        public string $storeId,
        public array $kinds,
        public bool $off,
    ) {}

    public function may(string $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }
}
