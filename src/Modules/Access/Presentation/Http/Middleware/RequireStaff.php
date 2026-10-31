<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Middleware;

use App\Http\AdminArea;
use Closure;
use Illuminate\Http\Request;
use Modules\Access\Application\Session\AuthenticatorGate;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages only a signed-in staff member reaches; anyone else goes to the sign-in page.
 *
 * A session that must set up an authenticator app first (amendment 91) reaches only the setup: every
 * other panel page sends it there. The setup's own routes, and signing out, are let through by name, so
 * every panel route keeps the one sign-in middleware other code looks for (AdminArea::SIGNED_IN).
 */
final readonly class RequireStaff
{
    public const string ALIAS = AdminArea::SIGNED_IN;

    /** The routes a session that must set up its app may reach. */
    public const array SETTING_UP_ROUTES = [
        'access.staff.sign-out',
        'access.staff.authenticator.setup.page',
        'access.staff.authenticator.setup',
        'access.staff.authenticator.setup.confirm',
        'access.staff.authenticator.recovery-codes.page',
    ];

    public const string SETUP_PATH = '/admin/authenticator/setup';

    public function __construct(
        private ActorContext $actors,
        private AuthenticatorGate $gate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->actors->current()->type !== ActorType::Staff) {
            return $request->expectsJson() ? response()->json(['message' => 'Unauthenticated.'], 401) : redirect('/admin/sign-in');
        }

        // Asked on every request, so a session open before the app became required is sent to the
        // setup at once (owner, 2026-10-31); the setup's own routes still answer it.
        $actor = $this->actors->current();

        if (! $request->routeIs(...self::SETTING_UP_ROUTES) && $actor->id !== null && $this->gate->mustSetUp($actor->id)) {
            return $request->expectsJson()
                ? response()->json(['message' => __('access::auth.set_up_app_first')], 403)
                : redirect(self::SETUP_PATH);
        }

        return $next($request);
    }
}
