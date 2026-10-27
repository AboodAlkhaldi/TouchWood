<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A photo the customer sent with the return, opened through Platform's expiring link. */
#[TypeScript]
final class ReturnPhotoData extends Data
{
    public function __construct(
        public string $mediaId,
        public int $position,
    ) {}
}
