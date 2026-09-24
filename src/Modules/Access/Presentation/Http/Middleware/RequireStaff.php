<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Middleware;

use App\Http\AdminArea;
use Closure;
use Illuminate\Http\Request;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages only a signed-in staff member reaches; anyone else goes to the sign-in page.
 */
final readonly class RequireStaff
{
    public const string ALIAS = AdminArea::SIGNED_IN;

    public function __construct(
        private ActorContext $actors,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->actors->current()->type !== ActorType::Staff) {
            return $request->expectsJson() ? response()->json(['message' => 'Unauthenticated.'], 401) : redirect('/admin/sign-in');
        }

        return $next($request);
    }
}
