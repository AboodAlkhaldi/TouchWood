<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * **One canonical host** (content.md §1.14, §2.3 row L5; platform.md §9.4; Q13 (a), owner 2026-11-10): the site
 * answers on `APP_URL`'s host without "www."; a request on `www.` + that host answers a permanent redirect to the
 * same path and query on the canonical one, so Google keeps one copy of each page.
 *
 * **Only that one other host** (the build's pick, plan K7): any other host - a staging host, an address by IP, a
 * health check - passes through untouched, so staging keeps its own. The target is built from `APP_URL` alone
 * (lesson 16), never from the Host header; only the path and query travel, and they stay on our host.
 *
 * A read answers 301. Anything else answers 308, which keeps its method and body: a form or a provider's
 * notification sent to the other host is not turned into a GET that loses it (the build's pick).
 *
 * Global and first (PlatformServiceProvider prepends it): nothing runs for the other host - no session, no store,
 * no query.
 */
final readonly class CanonicalHost
{
    public function __construct(
        private Config $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $canonical = $this->canonical();

        if ($canonical === null || strtolower($request->getHost()) !== 'www.'.$canonical['host']) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        return new RedirectResponse($canonical['root'].$request->getRequestUri(), $request->isMethodSafe() ? 301 : 308);
    }

    /**
     * Refuses an APP_URL on the `www.` host (PR 4 review): every address the site builds on it would be the other
     * host's, each answered with a redirect. Checked when the middleware is registered, so a server so configured
     * never starts. An APP_URL with no host (an unconfigured app, lesson 30) passes.
     *
     * @throws LogicException
     */
    public static function assertAppUrl(mixed $url): void
    {
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        if (is_string($host) && str_starts_with(strtolower($host), 'www.')) {
            throw new LogicException("APP_URL names the host \"{$host}\". The site's canonical host is without \"www.\" (content.md §1.14, Q13): set APP_URL to the address without it; the www. host answers a redirect there.");
        }
    }

    /**
     * APP_URL's scheme, host without "www." and port, or null when APP_URL names no host.
     *
     * @return array{host: string, root: string}|null
     */
    private function canonical(): ?array
    {
        $url = $this->config->get('app.url');
        $parts = is_string($url) ? parse_url($url) : false;
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';

        if ($host === '') {
            return null;
        }

        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return ['host' => $host, 'root' => ($parts['scheme'] ?? 'https').'://'.$host.$port];
    }
}
