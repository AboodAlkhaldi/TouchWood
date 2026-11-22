<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The store address format editor (frontend.md §3.7, the decision of 2026-09-19).
 *
 * Built in this stage rather than seeded once: a country's address form is **data**, and a store
 * that asks for a district today and a postal code tomorrow is a row changed, not a deploy.
 *
 * It is Access's own screen rather than a panel inside Platform's store editor, because the format
 * is Access's data and Platform may never reference Access.
 */
#[TypeScript]
final class AddressFormatPage extends Data
{
    /**
     * @param  list<AddressFormatStore>  $stores  the stores this person may change; the picker
     *                                            offers these and no others
     * @param  list<AddressFormatField>  $fields  the chosen store's fields, in its own order
     * @param  bool  $exists  false for a store with no format yet: nothing can be saved there
     *                        until one is written, and the form starts empty
     * @param  string  $displayTemplate  plain text with {field} placeholders. A placeholder with
     *                                   nothing in it disappears and a line left empty is dropped;
     *                                   nothing in it is executed
     * @param  int  $maxFields  how many fields one format may hold
     * @param  int  $maxLength  the largest a field's own maximum may be
     */
    public function __construct(
        public array $stores,
        public string $storeId,
        public bool $exists,
        public array $fields,
        public string $displayTemplate,
        public int $maxFields,
        public int $maxLength,
    ) {}
}
