<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\MyAccount;

use Modules\Access\Public\Dto\AddressDto;

/**
 * A customer's addresses in one store, with the shape that store asks them to take (spec §1.9).
 *
 * Grouped by store because an address belongs to one: prices, delivery and the fields themselves
 * are that country's, and an address in another country is a new address there, never a copy of
 * this one with the country changed.
 */
final readonly class MyAddressesInStoreDto
{
    /**
     * @param  bool  $hasFormat  false when the store has no address format yet. Nothing may be
     *                           saved there until staff give it one (§1.9), and the screen says so
     *                           rather than offering a form that would be refused
     * @param  list<AddressFieldDto>  $fields  the store's own fields, in its own order
     * @param  list<AddressDto>  $addresses  the default first, then the newest
     * @param  int  $limit  how many addresses this store allows one customer (amendment 41)
     */
    public function __construct(
        public string $storeId,
        public string $storeCode,
        public bool $hasFormat,
        public array $fields,
        public array $addresses,
        public int $limit,
    ) {}
}
