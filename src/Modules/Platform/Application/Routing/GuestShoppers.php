<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Routing;

use Modules\Platform\Public\Contracts\ShopperType;
use Modules\Platform\Public\Enums\ShopperKind;

/**
 * Everyone a guest: Platform's own answer until the module that owns customers binds ShopperType
 * (Access does, catalog.md amendment 14(a)) — a guest sees what either kind of account sees (P25).
 */
final class GuestShoppers implements ShopperType
{
    public function current(): ShopperKind
    {
        return ShopperKind::Guest;
    }
}
