<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A store one of Ops' forms may offer (ops.md §4): its id and its code, for a form takes one or the other
 * (§4.1), its name in the page's language, and whether it is on - a Super Admin is offered the off ones too.
 */
#[TypeScript]
final class StoreChoiceData extends Data
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public bool $isOn,
    ) {}
}
