<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** F4 - a customer's wishlist for staff, a block per store the reader covers (feedback.md §4.1). */
#[TypeScript]
final class WishlistSectionData extends Data
{
    /**
     * @param  list<WishlistStoreData>  $stores
     */
    public function __construct(
        public array $stores,
    ) {}
}
