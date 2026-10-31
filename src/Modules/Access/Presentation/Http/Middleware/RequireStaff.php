<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Middleware;

use App\Http\AdminArea;
use Closure;
use Illuminate\Http\Request;
use Modules\Access\Application\Session\StaffSessions;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages only a signed-in staff member reaches; anyone else goes to the sign-in page.
 *
 * A session that must set up an authenticator app first (amendment 91) reaches only the setup: every
 * other panel page sends it there. The setup's own routes, and signing out, are named with
 * `RequireStaff::ALIAS.':'.RequireStaff::SETTING_UP`, which lets such a session through.
 */
final readonly class RequireStaff
{
    public const string ALIAS = AdminArea::SIGNED_IN;

    /** The parameter for the routes a session setting up its app may reach. */
    public const string SETTING_UP = 'setting-up';

    public const string SETUP_PATH = '/admin/authenticator/setup';

    public function __construct(
        private ActorContext $actors,
        private StaffSessions $sessions,
    ) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        if ($this->actors->current()->type !== ActorType::Staff) {
            return $request->expectsJson() ? response()->json(['message' => 'Unauthenticated.'], 401) : redirect('/admin/sign-in');
        }

        if ($mode !== self::SETTING_UP && $this->sessions->mustSetUpAuthenticator()) {
            return $request->expectsJson() ? response()->json(['message' => 'Set up your authenticator app first.'], 403) : redirect(self::SETUP_PATH);
        }

        return $next($request);
    }
}
