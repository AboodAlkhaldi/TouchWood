<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A store a notice may be written for (ops.md §4.15 O6), with the zone its sending time is typed in. */
#[TypeScript]
final class NoticeStoreData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $isOn,
        public string $timezone,
    ) {}
}
