<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Http;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Cookie\QueueingFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Access\Application\Customer\GuestVisitors;
use Modules\Access\Public\Contracts\GuestIdentity;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * The guest id the browser sends, from the storefront's encrypted cookie (spec §1.7). Access reads
 * it; whoever first needs a guest — Sales, with the first cart line — creates it through `ensure`
 * (amendment 74), for the year the owner set (2026-09-20). A forged or empty value is treated as no
 * guest at all. One a request: an id created for it is the one the rest of the request reads.
 */
final class CookieGuestVisitors implements GuestIdentity, GuestVisitors
{
    private ?string $created = null;

    private ?object $createdFor = null;

    public function __construct(
        private readonly Container $app,
        private readonly Config $config,
        private readonly QueueingFactory $cookies,
    ) {}

    public function current(): ?string
    {
        $request = $this->app->make(Request::class);

        if ($this->createdFor === $request && $this->created !== null) {
            return $this->created;
        }

        $name = $this->config->get('access.storefront.guest_cookie');
        $id = is_string($name) && $name !== '' ? $request->cookie($name) : null;

        return is_string($id) && Ulids::valid($id) ? strtolower($id) : null;
    }

    public function ensure(): string
    {
        $current = $this->current();

        if ($current !== null) {
            return $current;
        }

        $name = $this->config->get('access.storefront.guest_cookie');
        $days = $this->config->get('access.storefront.guest_cookie_days');
        $id = strtolower((string) Str::ulid());

        if (is_string($name) && $name !== '') {
            $this->cookies->queue($this->cookies->make($name, $id, (is_int($days) ? $days : 365) * 24 * 60, '/', null, null, true, false, 'lax'));
        }

        $this->created = $id;
        $this->createdFor = $this->app->make(Request::class);

        return $id;
    }
}
