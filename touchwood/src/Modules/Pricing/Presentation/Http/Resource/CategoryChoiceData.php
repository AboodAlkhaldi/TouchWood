<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A category the discount dialog offers, with its path.
 */
#[TypeScript]
final class CategoryChoiceData extends Data
{
    /**
     * @param  list<string>  $path
     */
    public function __construct(
        public string $id,
        public array $path,
    ) {}
}
