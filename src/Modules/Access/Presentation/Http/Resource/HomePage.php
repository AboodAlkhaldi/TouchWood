<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The admin home (frontend.md §2.2; the owner's fix list, point 6): the cards the reader may see,
 * for the scope shown, and whether the scope switch is offered.
 */
#[TypeScript]
final class HomePage extends Data
{
    public function __construct(
        /** `all` or `store`. */
        public string $scope,
        /** All Stores is the reader's for at least one card, and there is a store to switch to. */
        public bool $offersAllStores,
        /** @var list<HomeCardBlock> */
        public array $cards,
    ) {}
}
