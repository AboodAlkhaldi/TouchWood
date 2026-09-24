<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A customer's addresses in one store, as staff read them (frontend.md §3.7, G2).
 *
 * Read only, always: a customer's profile is their own, and staff never edit an address of theirs
 * (§3.7). They are here because somebody answering a question about a delivery needs to see what
 * the courier was given.
 */
#[TypeScript]
final class CustomerAddressGroup extends Data
{
    /**
     * @param  list<AddressRow>  $addresses  the default first, then the newest
     */
    public function __construct(
        public string $storeId,
        public string $storeName,
        public array $addresses,
    ) {}
}
