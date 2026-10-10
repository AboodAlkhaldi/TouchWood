<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The warranties screen (catalog.md §4.4 S6).
 */
#[TypeScript]
final class WarrantiesPage extends Data
{
    /**
     * @param  list<WarrantyData>  $warranties
     */
    public function __construct(
        public array $warranties,
        public bool $mayChange,
    ) {}
}
