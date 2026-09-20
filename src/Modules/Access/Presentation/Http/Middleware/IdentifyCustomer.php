<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Access\Application\Session\CustomerSessions;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every storefront page asks the session who is here: the customer signed in, or nobody, in which
 * case the request stays a guest (IdentifyRequestActor). A session that is over — idle, blocked or
 * signed in under an older password — ends here.
 */
final readonly class IdentifyCustomer
{
    public const string ALIAS = 'access.identify-customer';

    public function __construct(
        private CustomerSessions $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->sessions->signedIn();

        return $next($request);
    }
}
