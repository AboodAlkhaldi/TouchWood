<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Platform\Presentation\Http\InMemoryUnmatchedAddresses;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A shop address under a store that no page answers - /sa/en/categoriez, mistyped (owner, 2026-10-20;
 * frontend.md §2.6).
 *
 * The shop's last route (routes.php): a page read here, under the store and the language, is answered not
 * found, which app/Http/ProblemDetails draws as the shop's own error page - the shop's frame, "This page
 * isn't available.", Back to the Store. An unknown store, or an off one, never gets that far: the store's
 * middleware answers it not found first, with Laravel's page, as before. Anything else sent here (a POST,
 * say) is answered not found as before, by Laravel's own page.
 *
 * Before that, a read (GET or HEAD) is offered to the modules' answers for an address nobody answers
 * (content.md §2.3 row L2), so an old address under a store - /sa/ar/old-page - can be sent on to its new
 * page. Not in the spec's two places: the coordinator's pick of the PR 2 review (2026-11-10), with the same
 * rules - reads only, the path alone, nothing of the session.
 *
 * Which store an address names is Platform's subject (ResolveStore), so the address is Platform's to
 * answer. `{store}` never matches a reserved path - /admin, /build, /storage, /api, /up (ReservedPaths) -
 * so neither does this.
 */
final readonly class ShopNotFoundController
{
    /** The addresses under /{store}/{locale}/ it answers: any of one segment or more. */
    public const string PATHS = '.+';

    public function __invoke(Request $request, InMemoryUnmatchedAddresses $addresses): RedirectResponse
    {
        return $addresses->answerRequest($request) ?? throw new NotFoundHttpException;
    }
}
