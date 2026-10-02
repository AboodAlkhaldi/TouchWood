<?php

declare(strict_types=1);

// The store address format editor (stage 2b, frontend.md §3.7, decided 2026-09-19).
return [
    'title' => 'Address Forms',
    'subtitle' => 'What each country asks a customer for, and how the address is printed.',
    'intro' => 'A country\'s address form is data, not a release. Change it here and the next address saved in that country follows the new shape.',

    'store' => 'Country',
    'no_stores' => 'You cannot change any country\'s address form.',
    'no_format' => 'This country has no address form yet, so no address can be saved there. Add the fields it asks for.',

    'fields' => 'Fields',
    'fields_hint' => 'In the order a customer fills them in. At most :count of them.',
    'field_key' => 'Name in the System',
    'field_key_hint' => 'Lower-case letters, digits and underscores, such as postal_code. It is what the printed form below refers to, and changing it on a field somebody has already used leaves their address without that part.',
    'label_ar' => 'Label in Arabic',
    'label_en' => 'Label in English',
    'required' => 'Required',
    'max_length' => 'Longest Allowed',
    'max_length_hint' => 'Between 1 and :count characters.',
    'move_up' => 'Move Up',
    'move_down' => 'Move Down',
    'remove_field' => 'Remove Field',
    'add_field' => 'Add Field',
    'no_fields' => 'No field yet. A form needs at least one.',

    'template' => 'How It Is Printed',
    'template_hint' => 'Plain text. Write {city} and that field\'s value takes its place; a field with nothing in it disappears, and a line left empty is dropped. Nothing here is run as code.',
    'template_fields' => 'Fields you can use: :keys',

    'save' => 'Save Address Form',
    'saved' => 'Address form saved',
    'existing_addresses' => 'Addresses already saved keep what they hold. One that no longer fits this form cannot be used for an order until the customer completes it, and their addresses page says so.',

    // Asked for by the Geist screens (frontend.md 1.10): empty states' titles, disabled
    // buttons' reasons and dialogs' own words.
    'no_stores_title' => 'No Address Forms to Change',
    'no_fields_title' => 'No Fields Yet',
    'first_already' => 'This field is already first.',
    'last_already' => 'This field is already last.',
    'too_many_fields' => 'A form holds at most :count fields.',
];
