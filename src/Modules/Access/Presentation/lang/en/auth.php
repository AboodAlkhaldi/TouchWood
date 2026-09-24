<?php

declare(strict_types=1);

// What the sign-in and account pages say after each step, in the admin panel and on the storefront.
// The screens themselves (stage 2b step 1) read the same file: there are no separate frontend
// translation files, so one line is written once and used on both sides (frontend.md §1.5).
return [
    'registered' => 'Welcome. Check your email to confirm your address.',
    'verification_sent' => 'The confirmation link is on its way.',
    'email_verified' => 'Your email address is confirmed.',
    'code_sent' => 'A code was sent to your phone.',
    'reset_link_sent' => 'If this email belongs to an account, a link to choose a new password is on its way.',
    'password_reset' => 'Your password was changed. Sign in with it.',
    'password_changed' => 'Your password was changed. Every other session was signed out.',
    'email_changed' => 'Your email was changed. Sign in with it.',
    'invitation_accepted' => 'Your account is ready. Sign in with your email and password.',
    'signed_out' => 'You signed out.',

    // The screens (A1-A9).
    'sign_in' => 'Sign in',
    'sign_in_subtitle' => 'The admin panel.',
    'sign_out' => 'Sign out',
    'email' => 'Work email',
    'password' => 'Password',
    'show_password' => 'Show the password',
    'hide_password' => 'Hide the password',
    'forgot_password' => 'Forgot your password?',
    'back_to_sign_in' => 'Back to sign in',

    'phone_title' => 'Your phone number',
    'phone_subtitle' => 'We will send a code to it to finish signing in.',
    'phone' => 'Phone number',
    'phone_hint' => 'With its country code, for example +966501234567.',
    'send_code' => 'Send the code',

    'code_title' => 'Enter the code',
    'code_sent_to' => 'The code went to :phone.',
    'code_digit' => 'Digit :number',
    'trust_browser' => 'Trust this browser for :days days',
    'confirm' => 'Confirm',
    'resend' => 'Send another code',
    'resend_in' => 'Another code in :seconds seconds',

    'forgot_title' => 'Choose a new password',
    'forgot_subtitle' => 'We will email you a link.',
    'send_link' => 'Send the link',

    'reset_title' => 'Your new password',
    'new_password' => 'New password',
    'confirm_password' => 'Repeat the password',
    'password_rule' => 'At least :count characters.',
    'save_password' => 'Save the password',

    'invitation_title' => 'Set up your account',
    'invitation_subtitle' => 'Welcome, :name.',
    'accept_invitation' => 'Create my account',

    'email_change_title' => 'Confirm your new email',
    'email_change_subtitle' => 'Nothing changes until you press the button.',

    /*
    | The shop's own screens (F3-F6). Separate keys from the panel's, because the words differ:
    | a shopper has an email, not a work email, and signs in to their own account rather than to
    | an admin panel.
    */
    'shop_sign_in_subtitle' => 'Your account, your addresses and your orders.',
    'customer_email' => 'Email',
    'first_name' => 'First name',
    'last_name' => 'Last name',
    'remember_me' => 'Keep me signed in for :days days',
    'no_account' => 'No account yet?',
    'create_account' => 'Create an account',
    'have_account' => 'Already have an account?',

    'register_title' => 'Create an account',
    'register_subtitle' => 'One account for every country we sell in.',
    'account_type' => 'Who is this account for?',
    'account_type_individual' => 'Myself',
    'account_type_individual_hint' => 'Shopping as a person.',
    'account_type_company' => 'My company',
    'account_type_company_hint' => 'Your company details and documents come next.',
    'account_type_permanent' => 'This choice can never be changed later.',
    'terms_accept' => 'I accept the terms of sale and the privacy policy.',

    'verify_title' => 'Confirm your email',
    'verify_subtitle' => 'We sent the link to this address:',
    'verify_link_hours' => 'The link is good for :count hours.',
    'verify_what_next' => 'Until you confirm it you can look around and fill a basket, but not order.',
    'verify_resend' => 'Send the link again',
    'verify_pending' => 'Confirm your email',
    'passwords_differ' => 'The two passwords are not the same.',
];
