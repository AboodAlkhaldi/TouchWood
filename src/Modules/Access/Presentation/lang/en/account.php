<?php

declare(strict_types=1);

// "Account & settings" — what a staff member's own settings screen says (frontend.md §3.2, B1-B4).
// The refusals are not here: those are access::errors, and the screen never writes its own wording
// for one.
return [
    'title' => 'Account & settings',
    'subtitle' => 'Your own details, how you sign in, and what we tell you about.',

    'tab' => [
        'account' => 'Account',
        'security' => 'Security',
        'notifications' => 'Notifications',
    ],

    'save' => 'Save',
    'saved' => 'Saved.',
    'cancel' => 'Cancel',

    // B1 — the picture and the profile.
    'picture' => 'Picture',
    'picture_hint' => 'So the people you work with recognise you in a list.',
    'choose_picture' => 'Choose a picture',
    'replace_picture' => 'Replace the picture',
    'remove_picture' => 'Remove the picture',
    'picture_removed' => 'It will be removed when you save.',
    'picture_chosen' => 'Chosen: :name. It appears when you save.',
    'no_picture' => 'No picture yet.',
    'picture_preparing' => 'Your picture is being prepared and appears here shortly.',

    'first_name' => 'First name',
    'last_name' => 'Last name',
    'job_title' => 'Job title',
    'date_of_birth' => 'Date of birth',
    'country' => 'Country',
    'address' => 'Address',
    'address_hint' => 'Optional.',

    'communication_language' => 'Language for emails and messages',
    'communication_language_hint' => 'Every email and sign-in code we send you comes in this language. It is not the language the panel is shown in — that is the ع / EN toggle in the sidebar, and it is remembered for this browser alone.',
    'language' => [
        'ar' => 'Arabic',
        'en' => 'English',
    ],

    // B1 — the email, which is read only for everybody but a Super Admin.
    'email' => 'Work email',
    'email_locked' => 'Ask an admin to change it.',
    'change_email' => 'Change email',
    'email_pending' => 'Waiting for :email. Your address changes when the link we sent there is used, and not before.',
    'email_dialog_title' => 'Change your work email',
    'email_dialog_body' => 'A link goes to the new address. Your email changes when somebody opens that link, so a mistyped address changes nothing.',
    'new_email' => 'New email',
    'send_link' => 'Send the link',
    'email_change_sent' => 'A link is on its way to the new address.',

    // B2 — the phone, behind the current password.
    'phone' => 'Phone',
    'phone_hint' => 'Where your sign-in codes are sent.',
    'no_phone' => 'No number on file.',
    'change_phone' => 'Change',
    'phone_dialog_title' => 'Change your phone',
    'phone_dialog_body' => 'Your sign-in codes go to this number, so we ask for your password first. The number you have now keeps working until you enter the code we send to the new one.',
    'new_phone' => 'New number',
    'new_phone_hint' => 'With its country code, such as +966501234567.',
    'current_password' => 'Current password',
    'send_code' => 'Send the code',
    'phone_code_sent' => 'A code is on its way to the new number.',
    'phone_code' => 'The code we sent',
    'confirm_phone' => 'Confirm the number',
    'phone_changed' => 'Your phone was changed. Every browser you trusted will ask for a code again.',

    // B3 — the password. There is no two-factor switch, and §2.7 says why.
    'change_password' => 'Change your password',
    'password_rule' => 'At least :count characters.',
    'new_password' => 'New password',
    'confirm_password' => 'Repeat the new password',
    'passwords_differ' => 'The two passwords are not the same.',
    'password_note' => 'Changing it signs out every other session, and every browser you trusted asks for a code again.',
    'two_factor_title' => 'Signing in',
    'two_factor_body' => 'Every staff member signs in with a code sent to their phone. There is no switch for it, and there is nothing here to turn off.',

    // B4 — the notification switches, each saved as it is flipped.
    'notifications_hint' => 'Each switch saves the moment you flip it.',
    'by_email' => 'Email',
    'in_panel' => 'In the panel',
    'switch_email_for' => 'Email me about :topic',
    'switch_panel_for' => 'Tell me in the panel about :topic',
    'topic' => [
        'NEW_ORDERS' => 'New orders',
        'COMPANY_APPLICATIONS' => 'Company applications',
        'LOW_STOCK' => 'Low stock',
        'CAMPAIGN_EXPIRY' => 'Campaigns about to end',
    ],
];
