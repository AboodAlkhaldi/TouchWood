<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A category or a brand a shop page links to: its name and current slug in the page's language.
 */
#[TypeScript]
final class ShopLinkData extends Data
{
    public function __construct(
        public string $name,
        public string $slug,
    ) {}
}
