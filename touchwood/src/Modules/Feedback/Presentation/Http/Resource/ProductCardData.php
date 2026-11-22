<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A product on a Support screen (feedback.md §4.1): its name and shop address in the page's language, its
 * photo's thumbnail, and whether the store shown sells it now, it is archived, or Catalog no longer has it.
 */
#[TypeScript]
final class ProductCardData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $href,
        public ?string $photo,
        public bool $sold,
        public bool $archived,
        public bool $removed,
    ) {}
}
