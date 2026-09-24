<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * E3 - the currencies (frontend.md 3.5).
 *
 * Created and edited in the panel, by a Super Admin alone [DECIDED 2026-09-19]: the permissions are
 * reserved, so no role can carry them.
 */
#[TypeScript]
final class CurrenciesPage extends Data
{
    /**
     * @param  list<CurrencyRow>  $currencies
     * @param  list<int>  $exponents  the numbers of decimal places a currency may have
     */
    public function __construct(
        public array $currencies,
        public array $exponents,
    ) {}
}
