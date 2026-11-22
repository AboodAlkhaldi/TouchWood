<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A product's warranty on its page: name, period (none: lifetime) and terms.
 */
#[TypeScript]
final class ShopWarrantyData extends Data
{
    /**
     * @param  array<string, mixed>  $terms  the structured text
     */
    public function __construct(
        public string $name,
        public ?int $periodMonths,
        public array $terms,
    ) {}
}
