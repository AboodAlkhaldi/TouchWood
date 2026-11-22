<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A draft's answer to one request: its text, or its file.
 */
#[TypeScript]
final class CompanyAnswerData extends Data
{
    public function __construct(
        public string $requestId,
        public ?string $text,
        public ?string $mediaId,
    ) {}
}
