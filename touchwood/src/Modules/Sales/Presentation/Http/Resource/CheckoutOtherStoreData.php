<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The store whose country the chosen address is in (shipping.md §1.2 `OTHER_STORE`): its name, and its home page to order from. */
#[TypeScript]
final class CheckoutOtherStoreData extends Data
{
    public function __construct(
        public string $name,
        public string $url,
    ) {}
}
