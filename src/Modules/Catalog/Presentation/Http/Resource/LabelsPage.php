<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The labels screen (catalog.md §4.4 S5).
 */
#[TypeScript]
final class LabelsPage extends Data
{
    /**
     * @param  list<LabelData>  $labels
     */
    public function __construct(
        public array $labels,
        public bool $mayChange,
    ) {}
}
