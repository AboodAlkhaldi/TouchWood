<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A category in the shop's menu (catalog.md §4.5, P20), its sub-categories in the menu's order.
 */
#[TypeScript]
final class ShopMenuItemData extends Data
{
    /**
     * @param  list<ShopMenuItemData>  $children
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public array $children,
    ) {}
}
