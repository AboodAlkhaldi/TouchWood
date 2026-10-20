<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A panel address no screen answers - /admin/staf, mistyped (owner, 2026-10-20; frontend.md §2.6).
 *
 * The panel's last route (admin-routes.php): a page read here, behind the panel's door, is answered
 * not found, which app/Http/ProblemDetails draws as the panel's own error page - the frame, "Page Not
 * Found", Back to Home. Anything else sent here (a POST, say) is answered not found as before, by
 * Laravel's own page. Platform reserves /admin, so the address is Platform's to answer.
 */
final readonly class PanelNotFoundController
{
    /**
     * The addresses under /admin it answers: any - but never the sign-in pages' (sign-in, sign-in/…),
     * which somebody signed out must reach as they are.
     */
    public const string PATHS = '(?!sign-in(?:/|$)).+';

    public function __invoke(): never
    {
        throw new NotFoundHttpException;
    }
}
