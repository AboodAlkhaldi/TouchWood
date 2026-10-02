<?php

declare(strict_types=1);

// The company's own page (b2b.md §4.5, amendment 14; frontend.md F11). The design's own Arabic is
// kept where it has a line; the rest was written for this page.
return [
    'title' => 'Company Account',
    'subtitle' => 'Your company, its applications, and how it pays.',

    // The status box, by where the account stands.
    'status' => [
        'new' => ['title' => 'Not Sent Yet', 'body' => "Fill in your company's details and upload its documents, then send the application. You can browse and fill your cart while it is reviewed."],
        'confirm_email' => 'Confirm your email address before you send the application.',
        'pending' => ['title' => 'Under Review', 'body' => 'A new application cannot be sent while one is under review. You see company prices now; ordering opens once you are approved.'],
        'approved' => ['title' => 'Approved', 'body' => 'You can order at company prices.'],
        'rejected' => ['title' => 'Not Approved', 'body' => 'Why: :reason'],
        'suspended' => ['title' => 'Suspended', 'body' => 'Why: :reason. You cannot order or change your company details until our team reinstates the account.'],
        // A rejected company reinstated since: what it was told then, on its own line (amendment 15(b)).
        'reinstated' => 'Reinstated: :reason',
    ],
    'missing' => 'Still missing: :items',
    'missing_documents' => 'documents (:count)',
    'missing_answers' => 'answers (:count)',
    'missing_not_accepted' => 'documents no longer accepted (:count)',
    'missing_flagged' => 'items marked in the last decision (:count)',
    'missing_marked' => 'fields not saved or not valid yet',
    'separator' => ', ',

    'start' => 'Start Application',
    'apply_again' => 'Apply Again',
    'change' => 'Change Company Details',
    'change_warning' => 'These changes go to our team as a new application. You keep ordering until you send them; from then until they are approved, you cannot order.',
    'send' => 'Send Application',
    'discard' => 'Discard Draft',
    'discard_confirm' => 'Discarding this draft also deletes any file only it holds.',
    'discard_yes' => 'Discard Draft',
    'cancel' => 'Cancel',
    'suspended_draft' => 'You have a draft that was not sent. While the account is suspended you can only discard it.',
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
        'note' => 'Optional',
    ],
    'saving' => 'Saving…',
    'saved' => 'Saved',
    // What the page says before sending a value it would not accept (amendment 16(a)).
    'check' => [
        'required' => 'Required.',
        'min' => 'At least :count characters.',
        'max' => 'At most :count characters.',
        'one_line' => 'On one line, without special characters.',
        'lines' => 'Without special characters.',
        'characters' => 'Letters, digits, spaces and dashes only.',
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
    'no_longer_accepted' => 'No longer accepted — remove it before you send.',
    'greyed' => 'No longer offered',
    'too_large' => 'This file is larger than :size MB.',
    'duplicate_file' => 'A file with this name is already under ":section". The same file cannot go into two sections.',
    'file_added' => 'File added',
    'no_file' => 'Couldn\'t upload the file. It may be larger than the server accepts.',

    'requests_hint' => 'Answer each one before you send.',
    'answer' => 'Your Answer',

    'reference' => 'Reference',
    'sent_at' => 'Sent :date',
    'decided_at' => 'Decided :date',
    'decided' => 'Decided',
    'state' => [
        'SUBMITTED' => 'Under Review',
        'APPROVED' => 'Approved',
        'REJECTED' => 'Not Approved',
    ],
    'decision_reason' => 'Why',
    'decision_note' => 'A Note from Our Team',
    'asked_flagged' => 'Marked to Change',
    'asked_requests' => 'Asked For',
    'sent_values' => 'What Was Sent',

    'address_hint' => 'A change of address is saved at once, without review.',
    'address_saved' => 'Address saved',
    // Picked from the account's saved addresses, and kept as a copy (amendment 16(f)).
    'address_pick' => 'Pick one of your saved addresses. Changing or deleting it there later does not change the one kept here.',
    'address_kept' => 'Kept Now',
    'address_none' => 'You have no saved addresses yet. Add one, and you come straight back here to pick it.',
    'address_add' => 'Add Address',
    'address_incomplete' => "Its country's format no longer accepts it: update it in your addresses first.",
    // The saved address the copy came from was changed since (amendment 17(b)).
    'address_changed' => 'The address kept here was changed in your addresses since. Pick it again to use the new one.',
    'sent' => 'Application sent',
    'discarded' => 'Draft discarded',

    // The side column: the application's lifecycle and nothing else (amendment 16(e)).
    'steps' => [
        'title' => 'Your Application',
        'send' => ['title' => 'Fill In and Send', 'body' => 'The details and the documents.'],
        'review' => ['title' => 'Our Team Reviews It', 'body' => 'Usually within two business days.'],
        'decision' => ['title' => 'The Decision', 'body' => 'Sent to you by email.'],
        'now' => 'You Are Here',
        'done' => 'Done',
        'approved' => 'Approved',
        'rejected' => 'Not Approved',
    ],
    // An approved company's bank account, in the main column (amendment 16(e)).
    'payment' => [
        'title' => 'Paying for Company Orders',
        'approved' => 'Pay by bank transfer to:',
        'iban' => 'IBAN',
        'bank' => 'Bank',
        'holder' => 'Account Holder',
        'copy' => 'Copy IBAN',
        'copied' => 'Copied',
        'off' => 'Bank transfer is temporarily unavailable — our team will contact you to arrange payment.',
    ],

    // Asked for by the Geist screens (frontend.md 1.10): empty states' titles, disabled
    // buttons' reasons and dialogs' own words.
    'discard_title' => 'Discard Draft',
    'saving_wait' => 'Wait until your changes are saved.',
    'send_incomplete' => 'Complete everything still missing before you send.',
    'address_none_title' => 'No Saved Addresses',

    // A company per store (b2b.md amendments 18-20; owner, 2026-10-02).
    'subtitle_in_store' => 'Your company in :store, its applications, and how it pays.',
    'apply_here' => 'Apply in This Store',
    'prefill_label' => 'Carried Over',
    'prefill' => "The name and the type come from your company in :store; the address, the numbers and the documents are this store's own.",
    'elsewhere' => 'Your Companies in Other Stores',
];
