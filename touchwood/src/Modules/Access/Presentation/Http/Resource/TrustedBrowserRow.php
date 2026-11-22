<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One browser allowed past the SMS code until the trust expires (spec §1.8).
 *
 * It carries no token: what is stored is a hash, and no screen needs it.
 */
#[TypeScript]
final class TrustedBrowserRow extends Data
{
    public function __construct(
        public string $id,
        public string $expiresAt,
    ) {}
}
