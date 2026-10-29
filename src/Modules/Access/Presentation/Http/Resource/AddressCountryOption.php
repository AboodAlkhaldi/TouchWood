<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A country the address form offers (access.md amendment 76(b); frontend.md §3.6, Q1-Q3).
 */
#[TypeScript]
final class AddressCountryOption extends Data
{
    /**
     * @param  bool  $hasPlaces  its region, city and district are picked from Platform's list; false,
     *                           they are typed
     * @param  ServedByStore|null  $servedBy  another store ships there: the country is shown out of
     *                                        reach, pointing to that store (Q2); null for this store's
     *                                        own countries and for one no store ships to (Q3)
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $otherName,
        public bool $hasPlaces,
        public ?ServedByStore $servedBy,
    ) {}
}
