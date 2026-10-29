<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\AdminArea;
use App\Http\StorefrontArea;
use Closure;
use Illuminate\Http\Request;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Symfony\Component\HttpFoundation\Response;

/**
 * No browser copy of a page that shows somebody's data (handoff §15.4; owner 2026-10-29): every admin
 * page, a shop page seen by a signed-in customer, and one a staff member looks at from the panel (it may
 * be a store that is off). A guest's shop page holds nobody's data, so it keeps the browser's instant Back.
 *
 * It sits right after the area's "who is here" in its middleware list, inside it: a test clears the
 * actor once that middleware returns, so anything further out would see the system instead.
 */
final readonly class KeepNoCopy
{
    public const string ALIAS = 'keep-no-copy';

    public function __construct(private ActorContext $actors) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($request->is(AdminArea::PREFIX, AdminArea::PREFIX.'/*')
            || $this->actors->current()->type === ActorType::Customer
            || $request->attributes->get(StorefrontArea::STAFF_VIEW) === true) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
