<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Reads (Y1): a page of the store's reads, the latest asked first, 50 at a time, how many are kept, and
 * the open entries the Problems tab's trigger counts.
 */
#[TypeScript]
final class ReadsData extends Data
{
    /**
     * @param  list<ReadRowData>  $reads
     */
    public function __construct(
        public array $reads,
        public ?string $next,
        public int $total,
        public int $openEntries,
    ) {}
}
