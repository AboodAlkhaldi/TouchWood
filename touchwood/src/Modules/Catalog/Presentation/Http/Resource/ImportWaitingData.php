<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What still waits before the products can be brought in, as Bring Products In counts it
 * (`ImportUndecided`): names, codes, addresses, sales, code changes, attribute changes.
 */
#[TypeScript]
final class ImportWaitingData extends Data
{
    public function __construct(
        public int $names,
        public int $codes,
        public int $addresses,
        public int $sales,
        public int $codeChanges,
        public int $attributeChanges,
    ) {}
}
