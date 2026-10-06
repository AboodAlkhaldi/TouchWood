<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * E1 - the stores (frontend.md 3.5), and Add Store for whoever may open one (platform.md §9.7 #3;
 * owner, 2026-10-06): a store is still created complete, in one step, never half-configured.
 */
#[TypeScript]
final class StoresPage extends Data
{
    /**
     * @param  list<StoreRow>  $stores  only the ones this person may see
     * @param  list<string>  $timezones  every IANA identifier, for the fields that offer a choice
     * @param  bool  $maySwitch  whether this person may turn stores on and off — a Super Admin
     *                           (platform.md §3; owner, 2026-10-01)
     * @param  NewStoreForm|null  $add  what Add Store offers; null for whoever may not open a store
     */
    public function __construct(
        public array $stores,
        public array $timezones,
        public bool $maySwitch,
        public ?NewStoreForm $add = null,
    ) {}
}
