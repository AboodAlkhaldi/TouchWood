<?php

declare(strict_types=1);

// C18, the security messages (ops.md §1.5 C18; access.md §2.3): codes by WhatsApp, links by email, in the
// person's language - the wording Access approved, moved here unchanged when Ops bound its own sender.
// Not a bell's words: these are never shown on a screen.
return [
    'email_verification' => [
        'subject' => 'Confirm your email address',
        'lines' => [
            'Hello :name,',
            'Thank you for creating an account. Open the link below to confirm this email address.',
            'The link works for a limited time. If you did not create an account, ignore this email.',
        ],
        'action' => 'Confirm my email',
    ],
    'customer_deletion_scheduled' => [
        'subject' => 'Your account will be deleted',
        'lines' => [
            'Hello :name,',
            'We received a request to delete your account. It will be deleted on :date.',
            'If you did not ask for this, or you changed your mind, sign in before that date and the deletion stops.',
        ],
    ],
    'staff_invitation' => [
        'subject' => 'Your invitation to the admin panel',
        'lines' => [
            'Hello :name,',
            'You have been invited to the admin panel. Open the link below to choose your password and confirm your phone number.',
            'The link works for a limited time. If you did not expect this invitation, ignore this email.',
        ],
        'action' => 'Accept the invitation',
    ],
    'staff_email_change' => [
        'subject' => 'Confirm your new email address',
        'lines' => [
            'Hello :name,',
            'This address was entered as the new email of your admin panel account. Open the link below to confirm it.',
            'Until you do, your current email stays in use. If you did not expect this, ignore this email.',
        ],
        'action' => 'Confirm this email',
    ],
    // Written 2026-10-30 (amendment 90), approved for now (owner, 2026-10-31; full review at the end): sent when a Super Admin with no
    // phone a code reaches sets a new number at sign-in. It names no number.
    'staff_phone_changed' => [
        'subject' => 'Your phone number was changed',
        'lines' => [
            'Hello :name,',
            'The phone number of your admin panel account was changed while signing in. Sign-in codes now go to the new number.',
            'If you did not do this, contact another Super Admin now.',
        ],
    ],
    // Written 2026-10-31 (amendment 91), approved for now (owner, 2026-10-31; full review at the end): a sign-in gave the right
    // password and then ran out of wrong authenticator app codes. The time only, in UTC.
    'staff_app_code_failed' => [
        'subject' => 'Someone used your password and failed the app code',
        'lines' => [
            'Hello :name,',
            'At :time someone signed in to your admin panel account with your password, then entered wrong codes from the authenticator app until the sign-in ended.',
            'If this was not you, someone has your password: reset it now.',
        ],
        'locked' => 'This has happened three times in 24 hours, so signing in with the app is locked until you reset your password.',
    ],
    'password_reset' => [
        'subject' => 'Choose a new password',
        'lines' => [
            'Someone asked to reset the password of your account. Open the link below to choose a new one.',
            'The link works for a limited time. If you did not ask for this, ignore this email: your password stays as it is.',
        ],
        'action' => 'Choose a new password',
    ],
    // B2B's decisions about a company (access.md amendment 48). One plain link, to the shop.
    'company_approved' => [
        'subject' => 'Your company account is approved',
        'lines' => [
            'Hello :name,',
            'Your company account has been approved: you can now place orders.',
        ],
        'note' => 'A note from our team: :note',
        'action' => 'Go to the shop',
    ],
    'company_rejected' => [
        'subject' => 'Your company application was not approved',
        'lines' => [
            'Hello :name,',
            'Your company application was not approved.',
            'The reason: :reason',
            'Sign in to correct your application and send it again.',
        ],
        'action' => 'Go to the shop',
    ],
    'company_suspended' => [
        'subject' => 'Your company account is suspended',
        'lines' => [
            'Hello :name,',
            'Your company account has been suspended, so it cannot place orders for now.',
            'The reason: :reason',
            'You can still sign in and see your past orders.',
        ],
        'action' => 'Go to the shop',
    ],
    // Which store's company a decision is about: an account may hold a company in each store
    // (b2b.md amendments 19(c), 20(i)).
    'company_store' => 'This is about your company in our :store store.',
    'phone_code' => 'Your verification code is :code. Do not share it with anyone.',
    'sign_in_code' => 'Your admin panel sign-in code is :code. If you did not try to sign in, change your password now.',
    // The WhatsApp number's reply to a person who writes to it (ops.md §9.8 point 5, W5; approved by the owner 2026-11-15).
    // :link is the main number's wa.me link.
    'auto_reply' => 'This number only sends verification codes and can\'t read replies. To reach us, message our main number: :link',
    // The red notice over the panel while WhatsApp is not set up (ops.md §9.8 W3). Written with the build; the owner's wording review is owed.
    'not_set_up_label' => 'WhatsApp Setup',
    'not_set_up' => 'WhatsApp is not set up: no codes can be sent.',
    // The same notice for email on a production server whose email is not set up (owner, 2026-11-22; ops.md §7). Super Admins only.
    'email_not_set_up_label' => 'Email Setup',
    'email_not_set_up' => 'Email is not set up: no emails can be sent.',
];
