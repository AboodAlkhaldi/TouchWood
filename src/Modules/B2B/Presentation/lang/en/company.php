<?php

declare(strict_types=1);

// The company's own page (b2b.md §4.5, amendment 14; frontend.md F11). The design's own Arabic is
// kept where it has a line; the rest was written for this page.
return [
    'title' => 'Company account',
    'subtitle' => 'Your company, its applications, and how it pays.',

    // The status box, by where the account stands.
    'status' => [
        'new' => ['title' => 'Not sent yet', 'body' => "Fill in your company's details and upload its documents, then send the application. You can browse and fill your cart while it is reviewed."],
        'confirm_email' => 'Confirm your email address before you send the application.',
        'pending' => ['title' => 'Under review', 'body' => 'A new application cannot be sent while one is under review. You see company prices now; ordering opens once you are approved.'],
        'approved' => ['title' => 'Approved', 'body' => 'You can order at company prices.'],
        'rejected' => ['title' => 'Not approved', 'body' => 'Why: :reason'],
        'suspended' => ['title' => 'Suspended', 'body' => 'Why: :reason. You cannot order or change your company details until our team reinstates the account.'],
        // A rejected company reinstated since: what it was told then, on its own line (amendment 15(b)).
        'reinstated' => 'Reinstated: :reason',
    ],
    'missing' => 'Still missing: :items',
    'missing_documents' => 'documents (:count)',
    'missing_answers' => 'answers (:count)',
    'separator' => ', ',

    'start' => 'Start your application',
    'apply_again' => 'Apply again',
    'change' => 'Change company details',
    'change_warning' => 'These changes go to our team as a new application. You keep ordering until you send them; from then until they are approved, you cannot order.',
    'send' => 'Send application',
    'send_missing' => 'Complete the documents (:done/:total)',
    'discard' => 'Discard this draft',
    'discard_confirm' => 'Discard this draft? Any file only it holds is deleted too.',
    'discard_yes' => 'Discard',
    'cancel' => 'Cancel',
    'suspended_draft' => 'You have a draft that was not sent. While the account is suspended you can only discard it.',
    // Send waits for a clean form (amendment 15(a)).
    'send_blocked' => 'Finish the fields marked above before you send: each is saved when you leave it.',
    'answers_sent' => 'Answers sent',
    'document_gone' => 'A document no longer asked for',

    'section' => [
        'details' => 'Company details',
        'details_hint' => 'As in the commercial registration',
        'documents' => 'Documents',
        'requests' => 'What our team asked for',
        'note' => 'A note for our team',
        'company' => 'Your company',
        'address' => 'Address',
        'sent' => 'Application sent',
        'history' => 'Your applications',
    ],

    'field' => [
        'name' => 'Company name',
        'company_type' => 'Company type',
        'choose' => 'Choose…',
        'other' => 'Other',
        'other_words' => 'What is your company? In your own words',
        'cr_number' => 'Commercial Registration number',
        'tax_number' => 'Tax number',
        'address' => 'Registered address',
        'note' => 'Optional',
    ],
    'saving' => 'Saving…',
    'saved' => 'Saved',
    'type_no_longer' => 'This type is no longer offered — choose again.',
    'flagged' => 'Marked in the last decision — change it before you send.',
    'flagged_document' => 'Marked in the last decision — upload a new file.',

    'documents_hint' => 'PDF, JPEG or PNG · up to :size MB each',
    'documents_done' => ':done of :total uploaded',
    'required' => 'Required',
    'optional' => 'Optional',
    'uploaded' => 'Uploaded :date',
    'choose_file' => 'Choose a file',
    'replace' => 'Replace',
    'open' => 'Open',
    'remove' => 'Remove',
    'no_longer_accepted' => 'No longer accepted — remove it before you send.',
    'greyed' => 'No longer offered',
    'too_large' => 'This file is larger than :size MB.',
    'file_added' => 'File added.',
    'no_file' => 'No file arrived. It may be larger than the server accepts.',

    'requests_hint' => 'Answer each one before you send.',
    'answer' => 'Your answer',

    'reference' => 'Reference',
    'sent_at' => 'Sent :date',
    'decided_at' => 'Decided :date',
    'decided' => 'Decided',
    'state' => [
        'SUBMITTED' => 'Under review',
        'APPROVED' => 'Approved',
        'REJECTED' => 'Not approved',
    ],
    'decision_reason' => 'Why',
    'decision_note' => 'A note from our team',
    'asked_flagged' => 'Marked to change',
    'asked_requests' => 'Asked for',
    'sent_values' => 'What was sent',

    'address_hint' => 'A change of address is saved at once, without review.',
    'address_save' => 'Save address',
    'address_saved' => 'Address saved.',
    'sent' => 'Your application was sent.',
    'discarded' => 'The draft was discarded.',

    // The side column.
    'steps' => [
        'title' => 'What happens after you send',
        'send' => ['title' => 'Send the application', 'body' => 'The details and the documents'],
        'review' => ['title' => 'Our team reviews it', 'body' => 'Usually within two business days'],
        'decision' => ['title' => 'The decision', 'body' => 'Sent to you by email'],
        'prices' => ['title' => 'Company prices', 'body' => 'Ordering at company prices'],
    ],
    'payment' => [
        'title' => 'Paying for company orders',
        'before' => 'Company orders are never paid online: by bank transfer, or arranged with our team. The account details show once you are approved.',
        'approved' => 'Pay by bank transfer to:',
        'iban' => 'IBAN',
        'bank' => 'Bank',
        'holder' => 'Account holder',
        'copy' => 'Copy',
        'copied' => 'Copied',
        'off' => 'Bank transfer is temporarily unavailable — our team will contact you to arrange payment.',
        'suspended' => 'Company orders are never paid online. Ordering is stopped while the account is suspended.',
    ],
    'before' => [
        'title' => 'Before approval',
        'body' => 'You can browse, fill your cart and see company prices — ordering opens once you are approved.',
    ],
];
