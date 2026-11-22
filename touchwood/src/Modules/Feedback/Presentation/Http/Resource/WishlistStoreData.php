<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One store's block of a customer's wishlist: its name, whether it is on, and what was saved there. */
#[TypeScript]
final class WishlistStoreData extends Data
{
    /**
     * @param  list<WishlistItemData>  $items
     */
    public function __construct(
        public string $name,
        public bool $isOpen,
        public array $items,
    ) {}
}
