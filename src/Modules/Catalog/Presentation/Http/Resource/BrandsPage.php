<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The brands screen (catalog.md §4.4 S1): every brand, whether the reader may change them, and — when
 * a deactivation is being prepared — the products it would reach.
 */
#[TypeScript]
final class BrandsPage extends Data
{
    /**
     * @param  list<BrandData>  $brands
     * @param  list<ReachedProductData>|null  $reached  the products of the brand named by `reach`
     * @param  list<CountryOptionData>  $countries  for the form's country picker; none for a reader who may not change
     */
    public function __construct(
        public array $brands,
        public bool $mayChange,
        public ?array $reached,
        public ?string $reachedFor,
        public array $countries,
    ) {}
}
