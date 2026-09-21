<?php

declare(strict_types=1);

// The names of Access's permissions (AccessPermissions).
return [
    'account' => [
        'register' => 'Create an account',
        'verify' => 'Verify their email and phone',
        'verify_email' => 'Verify an email address with its link',
        'update' => 'Edit their own account',
        'delete' => 'Delete their own account',
        'anonymize' => 'Anonymize deleted accounts',
    ],
    'session' => [
        'sign_in' => 'Sign in',
        'sign_out' => 'Sign out',
        'reset_password' => 'Reset a forgotten password',
    ],
    'address' => [
        'manage' => 'Manage their own addresses',
    ],
    'own_account' => [
        'update' => 'Edit their own staff account',
    ],
    'staff' => [
        'accept_invitation' => 'Accept a staff invitation',
        'invite' => 'Invite staff',
        'update' => 'Edit staff',
        'assign_role' => 'Change staff roles and stores',
        'disable' => 'Disable and enable staff',
        'view' => 'View staff',
    ],
    'role' => [
        'manage' => 'Manage saved roles',
    ],
    'customer' => [
        'view' => 'View customers',
        'block' => 'Block and unblock customers',
        'delete' => 'Delete customer accounts on request',
    ],
    'address_format' => [
        'update' => 'Edit address schemes',
    ],
    'settings' => [
        'update' => 'Change store settings',
    ],
    'staff_settings' => [
        'update' => 'Change staff sign-in and security settings',
    ],
    'super_admin' => [
        'manage' => 'Create and revoke Super Admins',
    ],
];
