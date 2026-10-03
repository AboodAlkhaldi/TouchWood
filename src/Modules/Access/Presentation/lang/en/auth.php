<?php

declare(strict_types=1);

// What the sign-in and account pages say after each step, in the admin panel and on the storefront.
// The screens themselves (stage 2b step 1) read the same file: there are no separate frontend
// translation files, so one line is written once and used on both sides (frontend.md §1.5).
return [
    'registered' => 'Account created; confirm your address from the email we sent',
    'verification_sent' => 'Confirmation link sent',
    'email_verified' => 'Email address confirmed',
    'code_sent' => 'Code sent to your phone',
    'reset_link_sent' => 'If this email belongs to an account, a reset link is on its way',
    'password_reset' => 'Password changed',
    'password_changed' => 'Password changed, and every other session was signed out',
    'email_changed' => 'Email changed',
    'invitation_accepted' => 'Account created',
    'signed_out' => 'Signed out',

    // The screens (A1-A9).
    'sign_in' => 'Sign In',
    'sign_in_subtitle' => 'The admin panel.',
    'sign_out' => 'Sign Out',
    // The shopper's menu in the shop's header (owner's #4, 2026-10-02).
    'my_account' => 'My Account',
    'email' => 'Work Email',
    'password' => 'Password',
    'show_password' => 'Show the password',
    'hide_password' => 'Hide the password',
    'forgot_password' => 'Reset Password',
    'back_to_sign_in' => 'Back to Sign In',

    'phone_title' => 'Your Phone Number',
    'phone_subtitle' => 'We will send a code to it to finish signing in.',
    'phone' => 'Phone Number',
    'phone_hint' => 'With its country code, for example +966501234567.',
    'send_code' => 'Send Code',

    'code_title' => 'Enter the Code',
    'code_sent_to' => 'The code went to :phone.',
    // The code field's one label: shadcn's InputOTP is one input, not a box per digit.
    'code_label' => 'SMS Code',
    'trust_browser' => 'Trust This Browser for :days Days',
    'confirm' => 'Verify Code',
    'resend' => 'Resend Code',
    'resend_in' => 'Resend Code in :seconds Seconds',

    'forgot_title' => 'Choose a New Password',
    'forgot_subtitle' => 'We will email you a link.',
    'send_link' => 'Send Link',

    'reset_title' => 'Your New Password',
    'new_password' => 'New Password',
    'confirm_password' => 'Repeat Password',
    'password_rule' => 'At least :count characters.',
    'save_password' => 'Save Password',

    'invitation_title' => 'Set Up Your Account',
    'invitation_subtitle' => 'Welcome, :name.',
    'accept_invitation' => 'Create Account',

    'email_change_title' => 'Confirm Your New Email',
    'email_change_subtitle' => 'Nothing changes until you press the button.',

    /*
    | The shop's own screens (F3-F6). Separate keys from the panel's, because the words differ:
    | a shopper has an email, not a work email, and signs in to their own account rather than to
    | an admin panel.
    */
    'shop_sign_in_subtitle' => 'Your account, your addresses and your orders.',
    'customer_email' => 'Email',
    'first_name' => 'First Name',
    'last_name' => 'Last Name',
    'remember_me' => 'Keep Me Signed In for :days Days',
    'no_account' => 'No account yet?',
    'create_account' => 'Create Account',
    'have_account' => 'Already have an account?',

    'register_title' => 'Create Account',
    'register_subtitle' => 'One account for every country we sell in.',
    'account_type' => 'Kind of Account',
    'account_type_individual' => 'Myself',
    'account_type_individual_hint' => 'Shopping as a person.',
    'account_type_company' => 'My Company',
    'account_type_company_hint' => 'Your company details and documents come next.',
    'account_type_permanent' => 'This choice can never be changed later.',
    'account_type_required' => 'Choose a kind of account.',
    'terms_accept' => 'I accept the terms of sale and the privacy policy.',

    'verify_title' => 'Confirm Your Email',
    'verify_subtitle' => 'We sent the link to this address:',
    'verify_link_hours' => 'The link is good for :count hours.',
    'verify_what_next' => 'Until you confirm it you can look around and fill a basket, but not order.',
    'verify_resend' => 'Resend Link',
    'verify_pending' => 'Confirm Your Email',
    'passwords_differ' => 'The two passwords are not the same.',

    // Asked for by the Geist screens (frontend.md 1.10): empty states' titles, disabled
    // buttons' reasons and dialogs' own words.
    'confirm_new_email' => 'Confirm New Email',
    'resend_wait_reason' => 'A new code can be sent once the wait is over.',
];
