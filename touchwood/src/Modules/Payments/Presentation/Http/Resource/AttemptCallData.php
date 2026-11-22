<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A call to MyFatoorah, with what was sent and answered as stored: keys and card details never were (rule 13, Q20). */
#[TypeScript]
final class AttemptCallData extends Data
{
    public function __construct(
        public string $id,
        public string $endpoint,
        public ?int $httpStatus,
        public int $tookMs,
        public string $at,
        public ?string $request,
        public ?string $response,
        public ?string $error,
    ) {}
}
