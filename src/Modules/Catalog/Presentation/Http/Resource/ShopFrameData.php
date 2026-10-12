<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Catalog's part of the shop's frame (catalog.md §4.5; owner, 2026-10-07, #9): the category menu
 * for this store, language and viewer, and the words the menu and the search box say.
 */
#[TypeScript]
final class ShopFrameData extends Data
{
    /**
     * @param  list<ShopMenuItemData>  $menu
     * @param  array<string, string>  $words
     */
    public function __construct(
        public array $menu,
        public array $words,
    ) {}
}
