<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** S9 - ask for a return (sales.md §4.1): each line with the pieces that may still be returned. */
#[TypeScript]
final class AskReturnPage extends Data
{
    /**
     * @param  list<OwnLineData>  $lines
     */
    public function __construct(
        public string $number,
        public array $lines,
        public ?string $returnDeadline,
        public string $storeTimezone,
    ) {}
}
