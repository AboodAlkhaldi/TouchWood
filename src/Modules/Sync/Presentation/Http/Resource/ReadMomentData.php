<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A read running (its start) or waiting (when it was asked), with its kind.
 */
#[TypeScript]
final class ReadMomentData extends Data
{
    public function __construct(
        public string $kind,
        public string $at,
    ) {}
}
