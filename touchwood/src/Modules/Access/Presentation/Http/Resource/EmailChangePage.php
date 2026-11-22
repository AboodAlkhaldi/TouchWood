<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A8 - confirming a new address (frontend.md 3.1). Opening the link shows this; only the button
 * changes anything.
 */
#[TypeScript]
final class EmailChangePage extends Data
{
    public function __construct(
        public string $token,
        public string $newEmail,
    ) {}
}
