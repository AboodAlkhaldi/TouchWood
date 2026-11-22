<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

/**
 * How many things wait behind a menu entry (frontend.md E7): shown beside the entry, and on the
 * admin home while it is above nothing. Named on the entry (MenuEntryDto::$count) and resolved only
 * when the menu is built for somebody the entry is offered to — so nobody sees a count for a screen
 * they may not open.
 */
interface MenuCount
{
    public function count(): int;
}
