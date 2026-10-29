<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What one store's address form offers, read when it is opened (frontend.md §3.6, F9 on Platform's
 * places; the owner, 2026-10-29: only what is shown, when it is shown).
 */
#[TypeScript]
final class AddressFormData extends Data
{
    /**
     * @param  list<AddressCountryOption>  $countries  the address countries, the store's own first
     * @param  list<PlaceOption>  $regions  the own country's regions, where it has a place list, so
     *                                      the first picker opens at once
     */
    public function __construct(
        public array $countries,
        public array $regions,
    ) {}
}
