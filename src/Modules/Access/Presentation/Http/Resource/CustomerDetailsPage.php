<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * G2 - one customer, as staff read them (frontend.md §3.7).
 *
 * **Every action here is admin-only** (R6) and each is offered only to somebody who holds it: the
 * screen is told what this reader may do rather than working it out from a role it cannot see.
 *
 * A customer is never edited by staff beyond blocking and deletion: their profile is their own.
 */
#[TypeScript]
final class CustomerDetailsPage extends Data
{
    /**
     * @param  list<CustomerAddressGroup>  $addresses  by store, and empty where they have none
     * @param  string  $locale  the language we write to them in, which is their own choice
     *                          Each flag is what Access says this reader may do **next**: an account is blocked or
     *                          unblocked, never both, and a closing is started or stopped, never started twice.
     */
    public function __construct(
        public CustomerRow $customer,
        public string $locale,
        public array $addresses,
        public bool $mayBlock,
        public bool $mayUnblock,
        public bool $mayStartDeletion,
        public bool $mayCancelDeletion,
        public int $deletionDays,
    ) {}
}
