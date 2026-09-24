<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * F3 - registering (frontend.md §3.6). The password rule is shown in words, and its number is the
 * setting's, so a shop that raises it says so on the form without a deploy.
 */
#[TypeScript]
final class CustomerRegisterPage extends Data
{
    public function __construct(
        public int $minimumLength,
    ) {}
}
