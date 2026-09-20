<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before the "web" group starts the session: the storefront's cookie and its row live as long
 * as the longest "remember me" a store may set, so a customer who chose it is still signed in when
 * they come back (spec §1.8). That is only the outer bound — Access ends a session sooner, after
 * the store's idle minutes or its remembered days (LaravelCustomerSessions).
 *
 * Put back afterwards, for whatever the application serves next.
 */
final readonly class UseStorefrontSession
{
    public const string ALIAS = 'access.storefront-session';

    public function __construct(
        private Config $config,
        private SessionManager $sessions,
        private Container $app,
        private Redirector $redirector,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $previous = $this->config->get('session.lifetime');
        $days = $this->config->get('access.storefront.session_days');
        $this->config->set('session.lifetime', (is_int($days) ? $days : 365) * 24 * 60);
        $this->useStore();

        try {
            return $next($request);
        } finally {
            $this->config->set('session.lifetime', $previous);
            $this->useStore();
        }
    }

    /**
     * A new store under the current lifetime, given to everything that keeps one: the "web" group
     * starts this same store, and redirects flash their errors and input into it.
     */
    private function useStore(): void
    {
        $this->sessions->forgetDrivers();
        $store = $this->sessions->driver();
        $this->app->instance('session.store', $store);
        $this->redirector->setSession($store);
    }
}
