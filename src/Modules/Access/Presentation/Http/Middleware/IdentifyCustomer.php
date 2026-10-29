<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Middleware;

use App\Http\StorefrontArea;
use Closure;
use Illuminate\Http\Request;
use Modules\Access\Application\Session\CustomerSessions;
use Modules\Access\Application\StaffView\StaffViews;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every storefront page asks the session who is here: the customer signed in, or nobody, in which
 * case the request stays a guest (IdentifyRequestActor). A session that is over — idle, blocked or
 * signed in under an older password — ends here. A staff view that holds is marked on the request,
 * so the page keeps no browser copy (handoff §15.4, owner 2026-10-29).
 */
final readonly class IdentifyCustomer
{
    public const string ALIAS = StorefrontArea::IDENTIFY;

    public function __construct(
        private CustomerSessions $sessions,
        private StaffViews $staffViews,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->sessions->signedIn();

        if ($this->staffViews->current() !== null) {
            $request->attributes->set(StorefrontArea::STAFF_VIEW, true);
        }

        return $next($request);
    }
}
