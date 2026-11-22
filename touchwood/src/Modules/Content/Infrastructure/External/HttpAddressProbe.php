<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\External;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory;
use Modules\Content\Application\Redirects\AddressProbe;
use Modules\Content\Domain\ValueObject\RedirectPath;
use Throwable;

/**
 * `AddressProbe` over HTTP: a GET of the site's own address, on `APP_URL`, as a guest with no cookie, following no
 * redirect, within ten seconds. Only this site is ever asked - the path is one the redirects keep, on our own root -
 * so no address typed by anyone sends the server elsewhere. A redirect's Location is read back as a path only when
 * it stays on this site.
 */
final readonly class HttpAddressProbe implements AddressProbe
{
    public const int TIMEOUT = 10;

    public function __construct(
        private Factory $http,
        private Config $config,
    ) {}

    public function answer(string $path): ?array
    {
        $root = rtrim((string) $this->config->get('app.url'), '/');

        try {
            $response = $this->http->withoutRedirecting()->timeout(self::TIMEOUT)->accept('text/html')->get($root.RedirectPath::encoded($path));
        } catch (Throwable) {
            return null;
        }

        $location = $response->header('Location');

        return ['status' => $response->status(), 'location' => $location === '' ? null : self::ownPath($location, $root)];
    }

    /** A Location on this site as a decoded path, or null for one elsewhere. */
    public static function ownPath(string $location, string $root): ?string
    {
        if (str_starts_with($location, $root.'/')) {
            $location = substr($location, strlen($root));
        } elseif (! str_starts_with($location, '/') || str_starts_with($location, '//')) {
            return null;
        }

        return RedirectPath::of($location);
    }
}
