<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One store's group on My Wishlist (feedback.md §4.1 F8, Q20): its id - what Remove names - code, name, whether it is on, its products. */
#[TypeScript]
final class MyWishlistGroupData extends Data
{
    /**
     * @param  list<WishlistItemData>  $items
     */
    public function __construct(
        public string $storeId,
        public string $storeCode,
        public string $name,
        public bool $isOpen,
        public array $items,
    ) {}
}
