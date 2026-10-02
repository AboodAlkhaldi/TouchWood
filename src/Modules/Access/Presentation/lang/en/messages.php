<?php

declare(strict_types=1);

// The security messages Access sends until Ops exists (spec §2.3).
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
    'password_reset' => [
        'subject' => 'Choose a new password',
        'lines' => [
            'Someone asked to reset the password of your account. Open the link below to choose a new one.',
            'The link works for a limited time. If you did not ask for this, ignore this email: your password stays as it is.',
        ],
        'action' => 'Choose a new password',
    ],
    // B2B's decisions about a company, until Ops (amendment 48). One plain link, to the shop.
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
];
