<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Ops' email provider (ops.md §2.2): the key of Brevo's transactional API, empty until the
    // server's environment sets it. Used only when OPS_EMAIL_DRIVER is "brevo" (config/ops.php).
    // The same key keeps the newsletter's lists when OPS_NEWSLETTER_DRIVER is "brevo": they are made in
    // the Brevo folder named here (ops.md §1.9). Brevo's webhook is accepted only with the secret the
    // owner gives it as a custom header (X-TouchWood-Secret); empty, every call is refused.
    'brevo' => [
        'key' => env('BREVO_API_KEY', ''),
        'newsletter_folder' => env('BREVO_NEWSLETTER_FOLDER_ID', ''),
        'webhook_secret' => env('BREVO_WEBHOOK_SECRET', ''),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
