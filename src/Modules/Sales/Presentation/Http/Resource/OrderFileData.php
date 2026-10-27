<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A transfer receipt the customer uploaded (§1.9), opened through Platform's expiring link. */
#[TypeScript]
final class OrderFileData extends Data
{
    public function __construct(
        public string $mediaId,
        public int $position,
        public string $uploadedAt,
    ) {}
}
