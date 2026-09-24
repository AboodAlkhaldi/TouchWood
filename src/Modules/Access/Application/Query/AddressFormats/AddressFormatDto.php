<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\AddressFormats;

use Modules\Access\Application\Query\MyAccount\AddressFieldDto;

/**
 * One store's address form, as the screen that edits it reads it (frontend.md §3.7, the decision of
 * 2026-09-19).
 *
 * `$exists` is false for a store staff have not given a format to yet: nothing can be saved there
 * until they do, and the screen starts from an empty form rather than pretending there is one.
 */
final readonly class AddressFormatDto
{
    /**
     * @param  list<AddressFieldDto>  $fields  in the store's own order
     * @param  array<string, int>  $orders  each field's position, by key
     */
    public function __construct(
        public string $storeId,
        public bool $exists,
        public array $fields,
        public array $orders,
        public string $displayTemplate,
    ) {}
}
