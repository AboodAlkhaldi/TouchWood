<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Closure;
use Modules\Platform\Public\Dto\AdminBannerDto;
use Modules\Platform\Public\Dto\StoreDto;

/** The banners over the admin panel (payments.md §4.2), registered by a module's service provider as its menu entries are. */
interface AdminBanners
{
    /** @param  class-string<AdminBanner>  ...$banners */
    public function register(string ...$banners): void;

    /**
     * @param  Closure(): list<StoreDto>  $heldStores  the stores the reader holds, read only if a banner asks
     * @return list<AdminBannerDto>
     */
    public function forCurrentActor(string $locale, Closure $heldStores): array;
}
