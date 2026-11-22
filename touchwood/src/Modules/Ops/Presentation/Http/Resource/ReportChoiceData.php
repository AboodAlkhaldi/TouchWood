<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Modules\Ops\Application\Reports\ReportKind;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A report this reader may open (ops.md §1.10, §4): its kind, the stores they may read it for, and whether every store's together. */
#[TypeScript]
final class ReportChoiceData extends Data
{
    /**
     * @param  list<StoreChoiceData>  $stores
     */
    public function __construct(
        public ReportKind $kind,
        public array $stores,
        public bool $allStores,
    ) {}
}
