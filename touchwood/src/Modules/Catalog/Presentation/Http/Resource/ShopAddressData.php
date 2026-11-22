<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A shop page's own address and the same page's in the other language (P19), and whether search
 * engines are kept out (a product not available now, P18).
 */
#[TypeScript]
final class ShopAddressData extends Data
{
    public function __construct(
        public string $canonical,
        public ?string $alternate,
        public ?string $alternateLocale,
        public bool $noindex,
    ) {}
}
