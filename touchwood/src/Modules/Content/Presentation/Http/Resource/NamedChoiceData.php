<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A choice named in both languages: a theme a campaign may link to, a campaign linked to a theme (content.md W4b,
 * W5b).
 */
#[TypeScript]
final class NamedChoiceData extends Data
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public ?string $nameEn,
    ) {}
}
