<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A ready reason (feedback.md §4.2 B4), in both languages: the dialog offers the one the item is written in. */
#[TypeScript]
final class ReasonData extends Data
{
    public function __construct(
        public string $id,
        public string $textAr,
        public string $textEn,
    ) {}
}
