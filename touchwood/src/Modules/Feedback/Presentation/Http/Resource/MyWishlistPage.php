<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** F8 - My Wishlist, grouped by store. */
#[TypeScript]
final class MyWishlistPage extends Data
{
    /**
     * @param  list<MyWishlistGroupData>  $stores
     */
    public function __construct(
        public array $stores,
    ) {}
}
