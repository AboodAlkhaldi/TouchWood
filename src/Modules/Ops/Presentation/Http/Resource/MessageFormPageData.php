<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** New Message (ops.md §4.15 O4): where this person may write to one customer, and where to many. */
#[TypeScript]
final class MessageFormPageData extends Data
{
    /**
     * @param  list<StoreChoiceData>  $toOne
     * @param  list<StoreChoiceData>  $toMany
     */
    public function __construct(
        public array $toOne,
        public array $toMany,
        public string $storeCode,
    ) {}
}
