<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A Content page's head for search engines and sharing (content.md §1.14): its title through the store's pattern
 * (`:title | :site`), its description and its share image - each the item's own, else its title, summary or picture,
 * else the store's defaults.
 */
#[TypeScript]
final class ContentHeadData extends Data
{
    public function __construct(
        public string $title,
        public ?string $description,
        public ?string $image,
    ) {}
}
