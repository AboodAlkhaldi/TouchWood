<?php

declare(strict_types=1);

// The names of Access's permissions (AccessPermissions).
return [
    'account' => [
        'register' => 'Create an Account',
        'verify' => 'Verify Their Email and Phone',
        'verify_email' => 'Verify an Email Address with Its Link',
        'update' => 'Edit Their Own Account',
        'delete' => 'Delete Their Own Account',
        'anonymize' => 'Anonymize Deleted Accounts',
    ],
    'session' => [
        'sign_in' => 'Sign In',
        'sign_out' => 'Sign Out',
        'reset_password' => 'Reset a Forgotten Password',
    ],
    'address' => [
        'manage' => 'Manage Their Own Addresses',
    ],
    'own_account' => [
        'update' => 'Edit Their Own Staff Account',
    ],
    'staff' => [
        'accept_invitation' => 'Accept a Staff Invitation',
        'invite' => 'Invite Staff',
        'update' => 'Edit Staff',
        'assign_role' => 'Change Staff Roles and Stores',
        'disable' => 'Disable and Enable Staff',
        'view' => 'View Staff',
    ],
    'role' => [
        'manage' => 'Manage Saved Roles',
    ],
    'customer' => [
        'view' => 'View Customers',
        'block' => 'Block and Unblock Customers',
        'delete' => 'Delete Customer Accounts on Request',
    ],
    'address_format' => [
        'update' => 'Edit Address Schemes',
    ],
    'settings' => [
        'update' => 'Change Store Settings',
    ],
    'staff_settings' => [
        'update' => 'Change Staff Sign-In and Security Settings',
    ],
    'super_admin' => [
        'manage' => 'Create and Revoke Super Admins',
    ],
];
