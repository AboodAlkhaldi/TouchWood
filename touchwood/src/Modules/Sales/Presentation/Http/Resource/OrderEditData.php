<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** An edit made to an order (§1.7): its reason, who and when. */
#[TypeScript]
final class OrderEditData extends Data
{
    public function __construct(
        public string $id,
        public string $reason,
        public ?string $by,
        public string $at,
    ) {}
}
