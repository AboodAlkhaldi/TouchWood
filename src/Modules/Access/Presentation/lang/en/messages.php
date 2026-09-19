<?php

declare(strict_types=1);

// The security messages Access sends until Ops exists (spec §2.3).
return [
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
    'phone_code' => 'Your verification code is :code. Do not share it with anyone.',
];
