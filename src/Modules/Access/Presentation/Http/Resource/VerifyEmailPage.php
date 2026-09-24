<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * F4 - what to do about an unconfirmed email (frontend.md §3.6).
 *
 * Their own address, so the page can say where the link went: somebody who mistyped it at
 * registration learns it here rather than by waiting for a link that will never arrive.
 */
#[TypeScript]
final class VerifyEmailPage extends Data
{
    public function __construct(
        public string $email,
        public int $linkHours,
    ) {}
}
