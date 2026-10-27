<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The store's account a company transfers to (§4.1 S5 3, S6), as the store typed it. */
#[TypeScript]
final class BankData extends Data
{
    public function __construct(
        public string $iban,
        public string $bank,
        public string $holder,
    ) {}
}
