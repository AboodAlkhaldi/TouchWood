<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A5 - choosing a new password (frontend.md 3.1). The rule is shown in words, and its number is
 * the setting's.
 */
#[TypeScript]
final class ResetPasswordPage extends Data
{
    public function __construct(
        public string $token,
        public int $minimumLength,
    ) {}
}
