<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * E8 - the address countries (platform.md §4.3), each with its marks, and Add Country's choices.
 */
#[TypeScript]
final class PlacesPage extends Data
{
    /**
     * @param  list<PlaceCountryRow>  $countries  sorted by name in the page's language, retired ones too
     * @param  list<StoreCountryOption>  $addable  every ISO country not on the list
     */
    public function __construct(
        public array $countries,
        public array $addable,
    ) {}
}
