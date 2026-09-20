<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Session\SessionManager;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before the "web" group starts the session: the admin panel's own cookie, limited to /admin,
 * lasting as long as a staff session may (spec §1.8). The idle limit is checked on every request
 * (LaravelStaffSessions). Put back afterwards, for the next request a test sends.
 */
final readonly class UseAdminSession
{
    public const string ALIAS = 'access.admin-session';

    public function __construct(
        private Config $config,
        private SessionManager $sessions,
        private Container $app,
        private Redirector $redirector,
        private StaffSecuritySettings $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $previous = [
            'session.cookie' => $this->config->get('session.cookie'),
            'session.path' => $this->config->get('session.path'),
            'session.lifetime' => $this->config->get('session.lifetime'),
        ];

        $this->config->set([
            'session.cookie' => $this->config->get('access.admin.session_cookie'),
            'session.path' => '/admin',
            'session.lifetime' => $this->settings->sessionMaxHours() * 60,
        ]);
        $this->useStore();

        try {
            return $next($request);
        } finally {
            $this->config->set($previous);
            $this->useStore();
        }
    }

    /**
     * A new store under the current cookie, given to everything that keeps one: the "web" group
     * starts this same store (the manager returns the driver it made), and redirects flash errors
     * and input into it — the redirector would otherwise keep an earlier store.
     */
    private function useStore(): void
    {
        $this->sessions->forgetDrivers();
        $store = $this->sessions->driver();
        $this->app->instance('session.store', $store);
        $this->redirector->setSession($store);
    }
}
