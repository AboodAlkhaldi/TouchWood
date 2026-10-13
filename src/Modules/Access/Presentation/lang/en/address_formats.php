<?php

declare(strict_types=1);

// The store address format editor (stage 2b, frontend.md §3.7, decided 2026-09-19).
return [
    'title' => 'Address Forms',
    'subtitle' => 'What each country asks a customer for, and how the address is printed.',
    'intro' => 'Change a country\'s address form here, and the next address saved there follows it.',

    'store' => 'Country',
    'no_stores' => 'You cannot change any country\'s address form.',
    'no_format' => 'No address can be saved in this country until you add the fields it asks for.',

    'fields' => 'Fields',
    'fields_hint' => 'In the order a customer fills them in, at most :count fields.',
    'field_key' => 'Name in the System',
    'field_key_hint' => 'Lower-case letters, digits and underscores, such as postal_code; changing it on a field already in use leaves those addresses without that part.',
    // Said under the box as it is typed (frontend.md §1.7), the domain's own rule (AddressField::KEY).
    'check' => [
        'key' => ':field takes 2 to 40 lowercase letters, digits and underscores, starting with a letter.',
    ],
    'label_ar' => 'Label in Arabic',
    'label_en' => 'Label in English',
    // Each field's group of inputs is named, so a screen reader hears which field it is in.
    'field_number' => 'Field :number',
    'required' => 'Required',
    'max_length' => 'Longest Allowed',
    'max_length_hint' => 'Between 1 and :count characters.',
    // Fields are reordered by a drag handle, by mouse, touch or keyboard (owner, 2026-10-03: shadcn's
    // dashboard-01 pattern). What a screen reader is told as a field moves, in the page's language:
    // the drag library's own words are English only.
    'reorder' => 'Reorder :field',
    // What a screen reader calls the handle in place of "button".
    'drag_role' => 'drag handle',
    'drag_instructions' => 'To move a field, focus its handle and press Space or Enter, move it with the arrow keys, then press Space or Enter to drop it, or Escape to put it back.',
    'drag_picked' => ':field picked up.',
    'drag_moved' => ':field moved to position :position of :total.',
    'drag_dropped' => ':field dropped at position :position of :total.',
    'drag_cancelled' => ':field put back.',
    'remove_field' => 'Remove Field',
    'add_field' => 'Add Field',
    'no_fields' => 'A form needs at least one field.',

    'template' => 'How It Is Printed',
    // The template named inside a sentence that says what is wrong with it (frontend.md §1.7).
    'template_subject' => 'The printed form',
    'template_hint' => 'Write {city} for that field\'s value; an empty field disappears, and so does a line left empty.',
    'template_fields' => 'Fields you can use: :keys',

    'save' => 'Save Address Form',
    'saved' => 'Address form saved',
    'existing_addresses' => 'Saved addresses that no longer fit this form cannot be used for an order until their customer completes them.',

    // Asked for by the Geist screens (frontend.md 1.10): empty states' titles, disabled
    // buttons' reasons and dialogs' own words.
    'no_stores_title' => 'No Address Forms to Change',
    'no_fields_title' => 'No Fields Yet',
    'too_many_fields' => 'A form holds at most :count fields.',
];
