<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * One address, as the form sends it (spec §1.9).
 *
 * Only the shape is here. **Which fields a store asks for is that store's format, not this file's**
 * - staff change it as data, with no deploy - so `fields` is a map whose keys and lengths the
 * domain checks against the format of the store being saved into.
 *
 * The map pin is not sent at all in this stage: no map provider is chosen, so it is left empty
 * (frontend.md §3.6, decided 2026-09-19).
 */
final class CustomerAddressRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>|string>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'string', 'max:64'],
            'address_id' => ['nullable', 'string', 'max:64'],
            'label' => ['required', 'string', 'max:50'],
            'recipient_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:32'],
            'is_default' => ['sometimes', 'boolean'],
            // At most sixty, as a format may hold (amendment 42): a map with more than that cannot
            // have come from one of our forms.
            'fields' => ['required', 'array', 'max:60'],
            'fields.*' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * The values by key, with anything empty dropped: a field left blank is a field not given,
     * and the domain decides whether its format minds.
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        $values = $this->input('fields');
        $fields = [];

        foreach (is_array($values) ? $values : [] as $key => $value) {
            if (is_string($key) && is_string($value) && trim($value) !== '') {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }
}
