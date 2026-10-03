<?php

declare(strict_types=1);

// "Account & settings" — what a staff member's own settings screen says (frontend.md §3.2, B1-B4).
// The refusals are not here: those are access::errors, and the screen never writes its own wording
// for one.
return [
    'title' => 'Account & Settings',
    'subtitle' => 'Your own details, how you sign in, and what we tell you about.',

    'tab' => [
        'account' => 'Account',
        'security' => 'Security',
        'sessions' => 'Sessions',
        'notifications' => 'Notifications',
    ],

    'save' => 'Save Changes',
    'saved' => 'Changes saved',
    'cancel' => 'Cancel',

    // B1 — the picture and the profile.
    'picture' => 'Picture',
    'picture_hint' => 'So the people you work with recognise you in a list.',
    'choose_picture' => 'Choose Picture',
    'replace_picture' => 'Replace Picture',
    'remove_picture' => 'Remove Picture',
    'picture_removed' => 'It will be removed when you save.',
    'picture_chosen' => 'Chosen: :name. It appears when you save.',
    'no_picture' => 'No picture yet.',
    'picture_preparing' => 'Your picture is being prepared and appears here shortly.',

    'first_name' => 'First Name',
    'last_name' => 'Last Name',
    'job_title' => 'Job Title',
    'date_of_birth' => 'Date of Birth',
    'country' => 'Country',
    // The country picker's search (Geist's Combobox, past two hundred countries).
    'country_search' => 'Search countries',
    'country_none' => 'No countries match “:query”.',
    'address' => 'Address',
    'address_hint' => 'Optional.',

    'communication_language' => 'Language for Emails and Messages',
    'communication_language_hint' => 'Every email and sign-in code we send you comes in this language. It is not the language the panel is shown in — that is the ع / EN toggle in the sidebar, and it is remembered for this browser alone.',
    'language' => [
        'ar' => 'Arabic',
        'en' => 'English',
    ],

    // B1 — the email, which is read only for everybody but a Super Admin.
    'email' => 'Work Email',
    'email_locked' => 'Ask an admin to change it.',
    'change_email' => 'Change Work Email…',
    // Geist's Note: a one- or two-word label, then one sentence (the batch B audit).
    'email_pending_label' => 'Pending Change',
    'email_pending' => 'Your address becomes :email once the link sent there is used.',
    'email_dialog_title' => 'Change Work Email',
    'email_dialog_body' => 'A link goes to the new address. Your email changes when somebody opens that link, so a mistyped address changes nothing.',
    'new_email' => 'New Email',
    'email_change_sent' => 'Link sent to the new address',

    // B2 — the phone, behind the current password.
    'phone' => 'Phone',
    'phone_hint' => 'Where your sign-in codes are sent.',
    'no_phone' => 'No number on file.',
    'change_phone' => 'Change Phone Number…',
    'phone_dialog_title' => 'Change Phone Number',
    'phone_dialog_body' => 'Your sign-in codes go to this number, so we ask for your password first. The number you have now keeps working until you enter the code we send to the new one.',
    'new_phone' => 'New Number',
    'new_phone_hint' => 'With its country code, such as +966501234567.',
    'current_password' => 'Current Password',
    'send_code' => 'Send Code',
    'phone_code_sent' => 'Code sent to the new number',
    'phone_code' => 'Verification Code',
    'confirm_phone' => 'Confirm Phone Number',
    'phone_changed' => 'Phone number changed. Every browser you trusted will ask for a code again.',

    // B3 — the password. There is no two-factor switch, and §2.7 says why.
    'change_password' => 'Change Password',
    'password_rule' => 'At least :count characters.',
    'new_password' => 'New Password',
    'confirm_password' => 'Repeat New Password',
    'passwords_differ' => 'The two passwords are not the same.',
    'password_note' => 'Changing it signs out every other session, and every browser you trusted asks for a code again.',
    'two_factor_title' => 'Signing In',
    'two_factor_body' => 'Every staff member signs in with a code sent to their phone. There is no switch for it, and there is nothing here to turn off.',

    // B4 — the notification switches, each saved as it is flipped.
    'notifications_hint' => 'Each switch saves the moment you flip it.',
    'by_email' => 'Email',
    'in_panel' => 'In the Panel',
    'topic' => [
        'NEW_ORDERS' => 'New Orders',
        'COMPANY_APPLICATIONS' => 'Company Applications',
        'LOW_STOCK' => 'Low Stock',
        'CAMPAIGN_EXPIRY' => 'Campaigns About to End',
    ],

    /*
    | The customer's own account in the shop (F7-F10). Its own keys, because the words differ: a
    | shopper has an email rather than a work email, their number is for a delivery rather than for
    | a sign-in code, and there is no panel and no trusted browser to speak of.
    */
    'shop_title' => 'My Account',
    'shop_subtitle' => 'Your details, how you sign in, and where we reach you.',
    'shop_tab' => [
        'profile' => 'Details',
        'security' => 'Password',
        'phone' => 'Phone',
        'addresses' => 'Addresses',
        'close' => 'Close Account',
    ],

    'shop_email' => 'Email',
    'shop_email_locked' => 'Your email address cannot be changed.',
    'shop_communication_language_hint' => 'Every email and message we send you comes in this language. It is not the language the shop is shown in - that is in the address of the page, and you can change it in the header.',

    'account_kind' => 'Kind of Account',
    'account_kind_locked' => 'Chosen when you registered, and it cannot be changed.',
    'home_store' => 'Your Store',
    'home_store_hint' => 'The country you registered in. You can shop in any of our countries whichever one this is.',

    'shop_phone_hint' => 'Where we reach you about an order and a delivery.',
    'shop_no_phone' => 'No number yet.',
    'shop_add_phone' => 'Add Phone Number',
    'shop_change_phone' => 'Change Phone Number',
    'shop_phone_dialog_title' => 'Your Phone Number',
    'shop_phone_dialog_body' => 'We send a code to the number you enter. The number you have now keeps working until that code is entered, so a mistyped number changes nothing.',
    'shop_phone_changed' => 'Phone number confirmed',
    'shop_password_note' => 'Changing it signs out every other browser you are signed in on.',

    'before_ordering' => 'Before You Can Order',
    'missing_email' => 'Confirm your email address.',
    'missing_phone' => 'Add a phone number and confirm it.',
    'may_order' => 'Your account is ready to order with.',

    // F9 - the address book, and F10 - closing the account.
    'addresses' => 'Addresses',
    'addresses_hint' => 'One list per country. The one marked as usual is the one we deliver to unless you pick another.',
    'no_addresses' => 'No address here yet.',
    'no_format' => 'We are not delivering to this country yet.',
    'address_full' => 'You have as many addresses here as this country allows (:count). Remove one to add another.',
    'add_address' => 'Add Address',
    'edit_address' => 'Edit Address',
    'delete_address' => 'Delete Address',
    'address_label' => 'Address Name',
    'address_label_hint' => 'For you alone, such as Home or Work.',
    'recipient_name' => 'Recipient Name',
    'address_phone' => 'Delivery Phone',
    'address_phone_hint' => 'With its country code, such as +966501234567.',
    'default_address' => 'Deliver Here by Default',
    'is_default' => 'Usual Address',
    'make_default' => 'Set as Usual Address',
    'address_incomplete' => 'This country now asks for something this address does not have. Edit it before you order.',
    'address_saved' => 'Address saved',
    'address_default_set' => 'Usual address set',
    'address_deleted' => 'Address deleted',
    'confirm_delete_address' => 'Delete Address “:label”',
    'confirm_delete_address_body' => 'It is gone for good. Any order already placed keeps the address it was sent to.',

    'close_account' => 'Close Account',
    'close_account_body' => 'Your account is locked the moment you confirm, and everything in it is anonymized :count days later. Signing in before then cancels it and nothing is deleted.',
    'close_account_signs_out' => 'Confirming signs you out everywhere at once, so this is the last page you will see while signed in.',
    'close_account_confirm' => 'Close Account',
    'closed' => 'Account closed. It is deleted on :date unless you sign in again before then, which cancels it.',

    // B5 — where I am signed in, and which browsers skip my code (owner, 2026-09-26).
    'sessions' => 'Where You Are Signed In',
    'sessions_hint' => 'Every browser signed in to your account right now. If you do not recognise one, end it.',
    'this_browser' => 'This Browser',
    'session_ip' => 'Address',
    'session_seen' => 'Last Used',
    'unknown_device' => 'Unknown Browser',
    'end_session' => 'Sign Out Browser',
    'end_this_session' => 'Sign Out This Browser',
    'end_this_session_warning' => 'This is the browser you are using. Ending it signs you out now.',

    'sign_out_everywhere' => 'Sign Out Everywhere',
    'sign_out_everywhere_body' => 'Ends every session, including this one, and makes every trusted browser ask for a code again. Use it when somebody else has had your account.',

    'trusted_browsers' => 'Browsers That Skip Your Code',
    'trusted_browsers_hint' => 'These sign in with your password alone, without an SMS code, until they expire. They are not signed in — that is the list above.',
    'no_trusted_browsers' => 'Every browser asks for a code.',
    'trusted_until' => 'Until :date',
    'forget_browser' => 'Forget Browser',
    'forget_all_browsers' => 'Forget All Browsers',
    // What each confirmation says, the consequence first (frontend.md §1.11: a confirm dialog before
    // ending another browser's session, Forget Browser and Forget All Browsers).
    'end_session_body' => 'That browser is signed out at once.',
    'forget_browser_body' => 'It stays signed in if it is now, and asks for a code at its next sign-in.',
    'forget_all_browsers_body' => 'Each one stays signed in if it is now, and asks for a code at its next sign-in.',

    'session_ended' => 'Browser signed out',
    'signed_out_everywhere' => 'Signed out everywhere. Every browser will ask for a code.',
    'trusted_browser_forgotten' => 'Browser forgotten. It will ask for a code next time.',
    'trusted_browsers_forgotten' => 'All browsers forgotten. Each will ask for a code next time.',

    // Asked for by the Geist screens (frontend.md 1.10): empty states' titles, disabled
    // buttons' reasons and dialogs' own words.
    'profile_title' => 'Profile',
    'saving_reason' => 'Saving your last change.',
    'no_trusted_browsers_title' => 'No Trusted Browsers',
    'no_addresses_title' => 'No Addresses Yet',
    'delete_address_open' => 'Delete Address…',
    'close_account_open' => 'Close Account…',
];
