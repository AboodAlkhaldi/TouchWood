<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * F1 - choosing a country (frontend.md 3.6).
 *
 * The one shop page that belongs to no store: it exists because the visitor has not chosen one.
 * The choice is remembered for a year, and somebody who has already made it never sees this page
 * again - they are sent straight to their store (platform.md 3).
 */
#[TypeScript]
final class ChooseStorePage extends Data
{
    /**
     * @param  list<StoreChoiceRow>  $stores
     */
    public function __construct(
        public array $stores,
    ) {}
}
