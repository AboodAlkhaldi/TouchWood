<?php

declare(strict_types=1);

// The company's own page (b2b.md §4.5, amendment 14; frontend.md F11). The design's own Arabic is
// kept where it has a line; the rest was written for this page.
return [
    'title' => 'Company Account',

    // The status box, by where the account stands.
    'status' => [
        'new' => ['title' => 'Not Sent', 'body' => 'Fill in your company\'s details and upload its documents, then send the application; you can browse and fill your cart while it is reviewed.'],
        'confirm_email' => 'Confirm your email address before you send the application.',
        'pending' => ['title' => 'Under Review', 'body' => 'You see company prices now, and ordering opens once you are approved.'],
        'approved' => ['title' => 'Approved', 'body' => 'You can order at company prices.'],
        'rejected' => ['title' => 'Not Approved', 'body' => 'Why: :reason'],
        'suspended' => ['title' => 'Suspended', 'body' => 'Why: :reason; until our team reinstates the account, you can\'t order or change your company details.'],
        // A rejected company reinstated since: what it was told then, on its own line (amendment 15(b)).
        'reinstated' => 'Reinstated: :reason',
    ],
    'missing' => 'Still missing: :items',
    'missing_documents' => 'documents (:count)',
    'missing_answers' => 'answers (:count)',
    'missing_not_accepted' => 'documents no longer accepted (:count)',
    'missing_flagged' => 'items marked in the last decision (:count)',
    'missing_marked' => 'fields not saved or not valid yet',
    'missing_phone' => 'a confirmed phone number',
    'separator' => ', ',

    // Send waits for the account's confirmed phone number (amendment 26(a)).
    'phone_note' => 'Send waits for a confirmed phone number. Fill in the form meanwhile: it saves as you go.',
    'phone_link' => 'Confirm Phone Number',

    'start' => 'Start Application',
    'apply_again' => 'Apply Again',
    'change' => 'Change Company Details',
    'change_warning' => 'These changes go to our team as a new application: you keep ordering until you send them, then can\'t order until they are approved.',
    'send' => 'Send Application',
    'discard_confirm' => 'Discarding this draft also deletes any file only it holds. This cannot be undone.',
    'discard_yes' => 'Discard Draft',
    'suspended_draft' => 'You have a draft that was not sent, and while the account is suspended you can only discard it.',
    'answers_sent' => 'Answers Sent',
    'document_gone' => 'A document no longer asked for',

    'section' => [
        'details' => 'Company Details',
        'details_hint' => 'As in the commercial registration.',
        'documents' => 'Documents',
        'requests' => 'What Our Team Asked For',
        'note' => 'A Note for Our Team',
        'company' => 'Your Company',
        'address' => 'Address',
        'sent' => 'Application Sent',
        'history' => 'Your Applications',
    ],

    'field' => [
        'name' => 'Company Name',
        'company_type' => 'Company Type',
        'choose' => 'Select a company type',
        'other' => 'Other',
        'other_words' => 'Company Type in Your Own Words',
        'cr_number' => 'Commercial Registration Number',
        'tax_number' => 'Tax Number',
        'address' => 'Registered Address',
        'note' => 'Note (Optional)',
    ],
    'saving' => 'Saving…',
    'saved' => 'Saved',
    // What the page says before sending a value it would not accept (amendment 16(a)).
    'check' => [
        'required' => ':field is required.',
        'min' => ':field needs at least :count characters.',
        'max' => ':field takes at most :count characters.',
        'one_line' => ':field goes on one line, without special characters.',
        'lines' => ':field takes no special characters.',
        'characters' => ':field takes only letters, digits, spaces and dashes.',
    ],
    'rule' => [
        'range' => ':min to :max characters.',
        'up_to' => 'Up to :max characters.',
        'code' => ':min to :max letters, digits, spaces or dashes.',
    ],
    'type_no_longer' => 'This type is no longer offered — choose again.',
    'flagged' => 'Marked in the last decision — change it before you send.',
    'flagged_document' => 'Marked in the last decision — upload a new file.',

    'documents_hint' => 'PDF, JPEG or PNG · up to :size MB each.',
    'documents_done' => ':done of :total uploaded',
    'required' => 'Required',
    'optional' => 'Optional',
    'uploaded' => 'Uploaded :date',
    'choose_file' => 'Choose File',
    'replace' => 'Replace File',
    'open' => 'Open File',
    'remove' => 'Remove File',
    'remove_open' => 'Remove File…',
    'remove_title' => 'Remove File',
    'remove_confirm' => 'The file under “:name” is taken off the draft, and you can upload another in its place.',
    'answered_file' => 'File uploaded',
    'no_longer_accepted' => 'No longer accepted — remove it before you send.',
    'greyed' => 'No longer offered',
    'too_large' => 'This file is larger than :size MB.',
    'duplicate_file' => 'A file with this name is already under “:section”, and the same file can\'t go into two sections.',
    'file_added' => 'File added',
    'no_file' => 'Couldn\'t upload the file: it may be larger than the server accepts. Choose a smaller file, or try again.',

    'requests_hint' => 'Answer each one before you send.',
    'answer' => 'Your Answer',

    'reference' => 'Reference',
    'sent_at' => 'Sent :date',
    'decided' => 'Decided',
    'state' => [
        'SUBMITTED' => 'Under Review',
        'APPROVED' => 'Approved',
        'REJECTED' => 'Not Approved',
    ],
    'decision_reason' => 'Reason',
    'decision_note' => 'Note from Our Team',
    'asked_flagged' => 'Marked to Change',
    'asked_requests' => 'Requested Items',
    'sent_values' => 'What Was Sent',
    'documents_sent' => 'Documents Sent',

    'address_hint' => 'A change of address is saved at once, without review.',
    'address_saved' => 'Address saved',
    // Picked from the account's saved addresses, and kept as a copy (amendment 16(f)).
    'address_pick' => 'Pick one of your saved addresses; changing or deleting it there later doesn\'t change the copy kept here.',
    'address_kept' => 'Current Address',
    'address_none' => 'Add one, and you come straight back here to pick it.',
    'address_add' => 'Add Address',
    'address_incomplete' => "Its country's format no longer accepts it: update it in your addresses first.",
    // The saved address the copy came from was changed since (amendment 17(b)).
    'address_changed' => 'The address kept here was changed in your addresses since, so pick it again to use the new one.',
    'sent' => 'Application sent',
    'discarded' => 'Draft discarded',

    // The side column: the application's lifecycle and nothing else (amendments 16(e), 26(b)).
    'steps' => [
        'title' => 'Your Application',
        'form' => 'Form',
        'review' => 'Under Review',
        'decision' => 'Decision',
        'not_started' => 'Not Started',
        // Not naming the button: it reads otherwise for a company of another store (apply_here).
        'form_start' => 'Start your application to fill in the form.',
        'form_now' => 'Fill in your company\'s details and documents, then send.',
        // The day it was sent is the page's own "Sent :date" (sent_at), as in the main column.
        'review_now' => 'Our team is checking it. You can browse and fill your cart meanwhile.',
        'decided' => 'Decided :date',
        'decision_approved' => 'You can order at company prices.',
        // The reason is in the status box: beside the card on a wide screen, above it on a phone.
        'decision_rejected' => 'See the reason on this page, and apply again.',
        'approved' => 'Approved',
        'rejected' => 'Not Approved',
        // Each step's circle, said in words for a screen reader.
        'mark' => [
            'done' => 'done',
            'upcoming' => 'not reached yet',
            'good' => 'approved',
            'bad' => 'not approved',
        ],
    ],
    // An approved company's bank account, in the main column (amendment 16(e)).
    'payment' => [
        'title' => 'Paying for Company Orders',
        'approved' => 'Pay by bank transfer to:',
        'iban' => 'IBAN',
        'bank' => 'Bank',
        'holder' => 'Account Holder',
        'copy' => 'Copy IBAN',
        'off' => 'Bank transfer is temporarily unavailable — our team will contact you to arrange payment.',
    ],

    // Asked for by the Geist screens (frontend.md 1.10): empty states' titles, disabled
    // buttons' reasons and dialogs' own words.
    'discard_title' => 'Discard Draft',
    'discard_open' => 'Discard Draft…',
    'saving_wait' => 'Wait until your changes are saved.',
    'send_incomplete' => 'Complete everything still missing before you send.',
    'address_none_title' => 'No Saved Addresses',
    'address_saved_list' => 'Saved Addresses',
    'address_search' => 'Search addresses',
    'address_search_none' => 'No addresses match “:query”',

    // A company per store (b2b.md amendments 18-20; owner, 2026-10-02).
    'subtitle_in_store' => 'Your company in :store, its applications, and how it pays.',
    'apply_here' => 'Apply in This Store',
    'prefill_label' => 'Carried Over',
    'prefill' => "The name and the type come from your company in :store; the address, the numbers and the documents are this store's own.",
    // When this store's list has no type of the same name (amendment 19(b)): the name alone.
    'prefill_name_only' => "The name comes from your company in :store; the type, the address, the numbers and the documents are this store's own.",
    'elsewhere' => 'Your Companies in Other Stores',
];
