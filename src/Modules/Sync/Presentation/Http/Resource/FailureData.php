<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Why a read failed or a store is failing (Q31): Sync's own reason - its code and its sentence in the page's
 * language - or the provider's words as they came, shown under "The provider said:" in `dir="auto"`.
 */
#[TypeScript]
final class FailureData extends Data
{
    public function __construct(
        public ?string $code,
        public ?string $text,
        public ?string $providerSaid,
    ) {}
}
