<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Home;

use Closure;
use Illuminate\Contracts\Container\Container;
use Modules\Platform\Public\Contracts\AdminBanner;
use Modules\Platform\Public\Contracts\AdminBanners;
use Throwable;

final class InMemoryAdminBanners implements AdminBanners
{
    /** @var list<class-string<AdminBanner>> */
    private array $banners = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(string ...$banners): void
    {
        array_push($this->banners, ...array_values($banners));
    }

    public function forCurrentActor(string $locale, Closure $heldStores): array
    {
        $shown = [];

        foreach ($this->banners as $banner) {
            // A banner's failure - a full disk under its cache, say - must not take down every admin page.
            try {
                /** @var AdminBanner $each */
                $each = $this->container->make($banner);
                array_push($shown, ...$each->forCurrentActor($locale, $heldStores));
            } catch (Throwable $error) {
                report($error);
            }
        }

        return $shown;
    }
}
