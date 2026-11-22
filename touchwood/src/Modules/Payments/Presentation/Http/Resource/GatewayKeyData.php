<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One mode's current key, as far as a page may know it (§1.2): its last 6 characters, never more. */
#[TypeScript]
final class GatewayKeyData extends Data
{
    public function __construct(
        public string $last6,
        public string $expiresOn,
        public int $daysLeft,
        public bool $rejected,
        public string $setAt,
    ) {}
}
