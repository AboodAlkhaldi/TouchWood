<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * **The stores a home card speaks for** (platform.md §2.6; dashboard.md §3.2, D8), one answer for every module's
 * cards: the store chosen on Home, or for All Stores every store - an off one only for whoever may switch stores,
 * as the panel's store filters offer it - **the base store first**, then by position.
 */
interface HomeStores
{
    /**
     * Null when a card guarded by `$permission` must read nothing: one store the reader does not hold it in, or All
     * Stores for a reader not holding it in every store. Home offers a card only where it is held; a card asks again,
     * as every read across stores does (handoff §19).
     *
     * @return list<StoreDto>|null
     */
    public function for(HomeScope $scope, string $permission): ?array;
}
