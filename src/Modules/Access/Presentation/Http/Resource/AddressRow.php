<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One saved address, as the address book lists it (frontend.md §3.6, F9).
 *
 * @property array<string, string> $fields
 */
#[TypeScript]
final class AddressRow extends Data
{
    /**
     * @param  array<string, string>  $fields  the store format's values, by key, for the form that
     *                                         edits this address
     * @param  string  $formatted  the store's own layout, which is what a courier is given
     * @param  bool  $isComplete  false when the store's format has asked for something since this
     *                            address was saved (amendment 41): it may not be used for an order
     *                            until the customer fills the gap in
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $recipientName,
        public string $phone,
        public array $fields,
        public string $formatted,
        public bool $isDefault,
        public bool $isComplete,
    ) {}
}
