<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The account an approved company transfers to, as its home store typed it (b2b.md §2.3).
 */
#[TypeScript]
final class CompanyBankAccountData extends Data
{
    public function __construct(
        public string $iban,
        public string $bank,
        public string $holder,
    ) {}
}
