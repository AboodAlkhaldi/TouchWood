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
     * The addresses under /admin it answers: any - but never those of the pages somebody signed out must
     * read as they are (Access's, outside the panel's door, with a page to read; `route:list`): signing in,
     * a forgotten password and its reset, an invitation, an email change confirmed. A mistyped address
     * under one of them is Laravel's plain not found, never a trip to sign in (stage 5 step 10). The
     * language chosen (`preferences`) is only ever sent, never read: a read of it stays the panel's, its
     * not-found page - else the sent route's "method not allowed" (the review of step 10).
     */
    public const array SIGNED_OUT = ['sign-in', 'password', 'invitation', 'email-change'];

    public const string PATHS = '(?!(?:sign-in|password|invitation|email-change)(?:/|$)).+';

    public function __invoke(): never
    {
        throw new NotFoundHttpException;
    }
}
