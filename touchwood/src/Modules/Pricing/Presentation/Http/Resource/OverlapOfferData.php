<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Another offer or discount in the same dates, as the note names it: its short description and dates.
 */
#[TypeScript]
final class OverlapOfferData extends Data
{
    public function __construct(
        public string $id,
        public string $description,
        public string $startsAt,
        public ?string $endsAt,
        public bool $alwaysWins,
    ) {}
}
