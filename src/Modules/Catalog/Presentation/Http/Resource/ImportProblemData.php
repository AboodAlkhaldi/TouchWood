<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One problem of a refused file (`ImportRefused`): where - the item or product, counted from 1, and
 * the field - and what it must be, in the file format's own words (English, as the format is).
 */
#[TypeScript]
final class ImportProblemData extends Data
{
    public function __construct(
        public string $at,
        public string $problem,
    ) {}
}
