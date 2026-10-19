<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A sale or a category discount an entry names (Q37): whether it still exists and its short description.
 */
#[TypeScript]
final class OfferRefData extends Data
{
    public function __construct(
        public string $id,
        public bool $exists,
        public ?string $description,
    ) {}
}
