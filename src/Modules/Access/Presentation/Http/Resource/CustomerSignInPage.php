<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * F5 - signing in to the shop (frontend.md §3.6).
 *
 * Customers do have "keep me signed in" - staff do not (access.md §1.8) - and the checkbox says how
 * long it lasts, in the number the setting actually uses rather than one written into the page.
 */
#[TypeScript]
final class CustomerSignInPage extends Data
{
    public function __construct(
        public int $rememberDays,
    ) {}
}
