<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use Illuminate\Http\Request;

/**
 * Who a change is answered to (ops.md §4.1, §4.15). The page sends its forms through Inertia and goes back with
 * its words; anything else keeps the JSON §4 promises, a plain POST included.
 */
final class Answers
{
    public static function fromPage(Request $request): bool
    {
        return $request->hasHeader('X-Inertia');
    }
}
