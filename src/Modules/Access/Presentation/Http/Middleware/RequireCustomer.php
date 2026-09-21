<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Symfony\Component\HttpFoundation\Response;

/**
 * What only a signed-in customer may reach. Anyone else goes back to the store's home page; the
 * sign-in page itself is built in the frontend stage, and this points at it then.
 */
final readonly class RequireCustomer
{
    public const string ALIAS = 'access.customer';

    public function __construct(
        private ActorContext $actors,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->actors->current()->type !== ActorType::Customer) {
            return redirect()->route('storefront.home');
        }

        return $next($request);
    }
}
