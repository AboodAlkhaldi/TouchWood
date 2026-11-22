<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

use Modules\Access\Domain\Model\StoreAddressFormat;
use Modules\Access\Domain\ValueObject\AddressField;

/**
 * One store's address form, as the editor sends it (spec §1.9, §3.3).
 *
 * Only the shape is here. **What makes a field acceptable is the domain's** - the key's spelling,
 * the labels' lengths, that a template names only fields the format has - and it answers that in
 * its own words. These rules are the outer edge: a form nobody could have made in a browser, or a
 * payload large enough to be a nuisance, is refused before any of that is read.
 */
final class AddressFormatRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>|string>
     */
    public function rules(): array
    {
        return [
            'fields' => ['required', 'array', 'min:1', 'max:'.StoreAddressFormat::FIELDS_MAX],
            'fields.*.key' => ['required', 'string', 'max:40'],
            'fields.*.label_ar' => ['required', 'string', 'max:60'],
            'fields.*.label_en' => ['required', 'string', 'max:60'],
            'fields.*.required' => ['required', 'boolean'],
            'fields.*.max_length' => ['required', 'integer', 'min:1', 'max:'.AddressField::LENGTH_MAX],
            'display_template' => ['present', 'string', 'max:2000'],
        ];
    }

    /**
     * The fields in the order the screen listed them, numbered from ten in tens.
     *
     * **The order is the list's, not a number anybody types.** Staff move a field up or down; what
     * that means as an integer is this file's business, and leaving gaps means inserting one later
     * does not renumber the rest.
     *
     * @return list<array<string, mixed>>
     */
    public function fields(): array
    {
        $values = $this->input('fields');
        $fields = [];
        $order = 10;

        foreach (is_array($values) ? $values : [] as $field) {
            if (! is_array($field)) {
                continue;
            }

            $fields[] = [
                'key' => is_string($field['key'] ?? null) ? trim($field['key']) : '',
                'label_ar' => is_string($field['label_ar'] ?? null) ? $field['label_ar'] : '',
                'label_en' => is_string($field['label_en'] ?? null) ? $field['label_en'] : '',
                'required' => filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOL),
                'max_length' => (int) ($field['max_length'] ?? 0),
                'order' => $order,
            ];

            $order += 10;
        }

        return $fields;
    }
}
