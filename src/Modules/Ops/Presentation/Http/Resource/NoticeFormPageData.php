<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** New and Edit Notice (ops.md §4.15 O6): the stores it may be written for, the one chosen, and on Edit the notice. */
#[TypeScript]
final class NoticeFormPageData extends Data
{
    /**
     * @param  list<NoticeStoreData>  $stores
     */
    public function __construct(
        public array $stores,
        public string $storeCode,
        public ?NoticeData $notice,
    ) {}
}
