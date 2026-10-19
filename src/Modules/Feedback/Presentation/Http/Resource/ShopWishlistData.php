<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Feedback's `feedback.wishlist` section (feedback.md §1.4): whether the signed-in customer saved the product in this store. */
#[TypeScript]
final class ShopWishlistData extends Data
{
    public function __construct(
        public bool $saved,
    ) {}
}
