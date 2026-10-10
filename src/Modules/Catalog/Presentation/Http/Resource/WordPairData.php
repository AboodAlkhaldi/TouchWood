<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A shared word pair (catalog.md §1.11), its words as search reads them.
 */
#[TypeScript]
final class WordPairData extends Data
{
    public function __construct(
        public string $id,
        public string $wordA,
        public string $wordB,
    ) {}
}
