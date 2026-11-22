<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Enums;

/**
 * What becomes of a product when its category or brand is deactivated (catalog.md §1.5, §1.6),
 * chosen for each product, or for all of them at once.
 */
enum ProductFate: string
{
    /** No longer listed, searched or suggested; a direct link shows "Not available now"; activating
     *  the category or brand brings it back. */
    case Hide = 'HIDE';

    /** It keeps the inactive category: unlisted, still reached by search and its link. Never for a
     *  brand — no product is left without an active one. */
    case Leave = 'LEAVE';

    /** To another active lowest category, or another active brand. */
    case Move = 'MOVE';
}
