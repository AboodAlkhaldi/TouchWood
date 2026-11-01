<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One of a store's Promotions segments a form may pick (ops.md §4.3, §4.4): its names, and how many it holds now. */
#[TypeScript]
final class SegmentChoiceData extends Data
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public int $memberCount,
    ) {}
}
