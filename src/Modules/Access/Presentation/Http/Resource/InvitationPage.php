<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A6 - accepting an invitation (frontend.md 3.1). The name and address are shown but not editable;
 * the phone an admin entered may be corrected, because the code is about to go to it.
 */
#[TypeScript]
final class InvitationPage extends Data
{
    public function __construct(
        public string $token,
        public string $name,
        public string $email,
        public string $phone,
        public int $minimumLength,
    ) {}
}
