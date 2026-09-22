<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Inertia\Ssr\SsrRenderFailed;

/**
 * A page that could not be rendered on the server (frontend.md §1.3).
 *
 * Nobody sees an error: Inertia hands the page back unrendered and the browser renders it instead.
 * The cost is a slower first paint and no markup for a crawler, which is exactly the kind of fault
 * that goes unnoticed for weeks unless it is written down every time it happens.
 *
 * It is logged as a warning, not an error: the person was served.
 */
final class LogSsrFailure
{
    public function handle(SsrRenderFailed $event): void
    {
        Log::warning('A page could not be rendered on the server; the browser rendered it instead.', [
            'component' => $event->page['component'] ?? null,
            'url' => $event->page['url'] ?? null,
            'type' => $event->type->value,
            'error' => $event->error,
            'hint' => $event->hint,
            // The page's props are not logged: they hold whatever the screen was showing, which for
            // an admin screen is somebody's data.
        ]);
    }
}
