<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One country in the address book, with what the customer has there (frontend.md §3.6, F9).
 *
 * Every store is listed, not only the ones they have an address in: a customer may shop in any of
 * our countries whichever one they registered in, so the book is the list of places they could
 * order from - which is how "add one here" is offered at all.
 */
#[TypeScript]
final class AddressBookStore extends Data
{
    /**
     * @param  bool  $hasFormat  false when staff have not given this store an address shape yet.
     *                           Nothing can be saved there, and the screen says so rather than
     *                           offering a form that would be refused
     * @param  list<AddressFieldRow>  $fields  in the store's own order
     * @param  list<AddressRow>  $addresses  the default first, then the newest
     * @param  bool  $full  they hold as many as this store allows, so nothing more may be added
     *                      until one is removed
     */
    public function __construct(
        public string $storeId,
        public string $storeCode,
        public string $storeName,
        public bool $hasFormat,
        public array $fields,
        public array $addresses,
        public int $limit,
        public bool $full,
    ) {}
}
