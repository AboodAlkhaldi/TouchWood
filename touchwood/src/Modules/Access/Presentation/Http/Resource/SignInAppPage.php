<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The second step for whom the authenticator app is the method (amendment 91): a code from the app,
 * or a recovery code instead, and the same "trust this browser" as after an SMS code.
 */
#[TypeScript]
final class SignInAppPage extends Data
{
    public function __construct(
        public int $trustHours,
    ) {}
}
