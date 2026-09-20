<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Http;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Modules\Access\Application\Customer\GuestVisitors;
use Modules\Access\Infrastructure\Eloquent\Ulids;

/**
 * The guest id the browser sends, from the storefront's encrypted cookie (spec §1.7). Access reads
 * it; whoever first needs a guest — Sales, with the first cart line — writes it, for the year the
 * owner set (2026-09-20). A forged or empty value is treated as no guest at all.
 */
final readonly class CookieGuestVisitors implements GuestVisitors
{
    public function __construct(
        private Container $app,
        private Config $config,
    ) {}

    public function current(): ?string
    {
        $request = $this->app->make(Request::class);
        $name = $this->config->get('access.storefront.guest_cookie');
        $id = is_string($name) && $name !== '' ? $request->cookie($name) : null;

        return is_string($id) && Ulids::valid($id) ? strtolower($id) : null;
    }
}
