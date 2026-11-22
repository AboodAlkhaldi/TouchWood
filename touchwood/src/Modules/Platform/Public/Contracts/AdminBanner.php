<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Closure;
use Modules\Platform\Public\Dto\AdminBannerDto;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * One module's banners over the panel. It runs on every admin page: it must cost next to nothing when it has
 * nothing to say, and asks who may read each banner itself.
 */
interface AdminBanner
{
    /**
     * @param  Closure(): list<StoreDto>  $heldStores  the stores the reader holds, read only if asked
     * @return list<AdminBannerDto>
     */
    public function forCurrentActor(string $locale, Closure $heldStores): array;
}
