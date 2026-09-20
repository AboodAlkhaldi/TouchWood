<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\UpdateStoreAddressFormat;

use Modules\Access\Application\Address\StartingAddressFormat;

/**
 * One store's address form, as staff change it (spec §1.9, §3.3). Each field is
 * `{key, label_ar, label_en, required, max_length, order}`; the template is plain text with
 * `{field}` placeholders.
 *
 * @see StartingAddressFormat for what a store starts with
 */
final readonly class UpdateStoreAddressFormat
{
    /**
     * @param  list<array<string, mixed>>  $fields
     */
    public function __construct(
        public string $storeId,
        public array $fields,
        public string $displayTemplate,
    ) {}
}
