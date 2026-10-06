<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The admin home (frontend.md §2.2; the owner's fix list, point 6): the cards the reader may see, for
 * the store chosen in its switcher or All Stores (the owner, 2026-10-06; access.md amendment 64).
 */
#[TypeScript]
final class HomePage extends Data
{
    public function __construct(
        /** The store shown; null for All Stores. */
        public ?string $storeCode,
        /** All Stores is the reader's for at least one card. */
        public bool $offersAllStores,
        /** @var list<StoreOptionBlock> the reader's stores, for the switcher */
        public array $stores,
        /** @var list<HomeCardBlock> */
        public array $cards,
        /** The chosen store's zone, for the moments on the page; null for All Stores (frontend.md §1.10). */
        public ?string $storeTimezone,
    ) {}
}
