<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * E1 - the stores (frontend.md 3.5).
 *
 * No store is made here: opening a country stays a console command, so a store is created complete,
 * in one command, and can never exist half-configured [DECIDED 2026-09-19].
 */
#[TypeScript]
final class StoresPage extends Data
{
    /**
     * @param  list<StoreRow>  $stores  only the ones this person may see
     * @param  list<string>  $timezones  every IANA identifier, for the one field that offers a choice
     */
    public function __construct(
        public array $stores,
        public array $timezones,
    ) {}
}
