<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Middleware;

use App\Http\AdminArea;
use Closure;
use Illuminate\Http\Request;
use Modules\Access\Application\Session\StaffSessions;
use Symfony\Component\HttpFoundation\Response;

/**
 * After the session starts: the request acts as the staff member signed in, if the session is
 * still good; a session that is not good any more ends here.
 */
final readonly class IdentifyStaff
{
    public const string ALIAS = AdminArea::IDENTIFY;

    public function __construct(
        private StaffSessions $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->sessions->signedIn();

        return $next($request);
    }
}
