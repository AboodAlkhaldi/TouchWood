<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The admin home (frontend.md §2.2; the owner's fix list, point 6), since build 7 the dashboard (dashboard.md):
 * the cards the reader may see, for the store chosen in its switcher or All Stores (the owner, 2026-10-06;
 * access.md amendment 64) and the period chosen (§3.3) - the greeting row's buttons, the period's sections and
 * Needs Attention apart.
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
        /** @var list<HomeCardBlock> Needs Attention's cards (§2 J) */
        public array $cards,
        /** The chosen store's zone, for the moments on the page; null for All Stores (frontend.md §1.10). */
        public ?string $storeTimezone,
        /** The period shown: a HomePeriod. */
        public string $period = 'month',
        /** @var list<HomePeriodBlock> */
        public array $periods = [],
        /** "Good morning, Sara" (§2 A, D1), by the store's clock. */
        public string $greeting = '',
        /** @var list<HomeCardBlock> the period's sections (§2 B-I) */
        public array $sections = [],
        /** @var list<HomeCardBlock> the greeting row's buttons (§2.1) */
        public array $actions = [],
        /** When the oldest figure shown was read (§7, D11), ISO 8601; null with nothing shown. */
        public ?string $readAt = null,
    ) {}
}
