<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A category a smart bar or a listing banner may be shown on (content.md §1.6, §1.9), with the path to it per language. */
#[TypeScript]
final class CategoryChoiceData extends Data
{
    public function __construct(
        public string $id,
        public string $pathAr,
        public string $pathEn,
    ) {}
}
