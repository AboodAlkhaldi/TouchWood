<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The jobs the reader holds in the order's store (§4.1): what the page may offer; the order's state decides the rest. */
#[TypeScript]
final class OrderActionsData extends Data
{
    public function __construct(
        public bool $mayApprove,
        public bool $mayEdit,
        public bool $mayCancel,
        public bool $mayFulfil,
        public bool $mayRecordMoney,
        public bool $mayManageReturns,
    ) {}
}
