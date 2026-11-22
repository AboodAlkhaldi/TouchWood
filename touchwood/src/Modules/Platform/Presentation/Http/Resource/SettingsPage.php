<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * E4 - the settings (frontend.md 3.5).
 */
#[TypeScript]
final class SettingsPage extends Data
{
    /**
     * @param  list<SettingGroup>  $groups  a module with nothing for this person is not here at all
     * @param  list<StoreOption>  $stores  the page's own store filter (platform.md §9.10): the stores
     *                                     where the person may change a store's setting; empty when
     *                                     they may change none
     */
    public function __construct(
        public array $groups,
        /** The store a per-store setting applies to, named for the screen to say so. */
        public ?string $storeName,
        /** That store's code, as its filter and its saves carry it. */
        public ?string $storeCode,
        public array $stores,
        /** That store's zone, for the moments on the page (frontend.md §1.10). */
        public ?string $storeTimezone,
    ) {}
}
