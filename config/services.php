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
        // The nightly read of the lists' unsubscribes (ops.md §9.6 #1): off until one real run on the owner's
        // Brevo account has shown the right people (docs/HANDOFF.md). Off, the scheduled job does nothing.
        'nightly_read' => (bool) env('BREVO_NIGHTLY_READ', false),
    ],

    // Ops' WhatsApp (ops.md §9.8): codes and the gateway key's critical alerts go through Meta's WhatsApp Cloud
    // API, direct. Every key is the server's, in .env only. A missing one never stops the site (W3): codes are
    // refused with words, and the Super Admins are told. The Graph API version is pinned here, checked against Meta's
    // changelog when built (developers.facebook.com/docs/graph-api/changelog/versions: v26.0 the latest listed).
    'whatsapp' => [
        'graph_version' => 'v26.0',
        'graph_url' => 'https://graph.facebook.com',
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID', ''),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN', ''),
        'code_template' => env('WHATSAPP_CODE_TEMPLATE', ''),
        'app_secret' => env('WHATSAPP_APP_SECRET', ''),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN', ''),
        'main_number' => env('WHATSAPP_MAIN_NUMBER', ''),
        'alert_key_expiring' => env('WHATSAPP_ALERT_KEY_EXPIRING', ''),
        'alert_key_refused' => env('WHATSAPP_ALERT_KEY_REFUSED', ''),
    ],

    // Platform's machine translation (content.md §2.2): "deepl" sends staff text to DeepL with DEEPL_API_KEY,
    // the server's token, held by the owner; "fake" marks each text and sends nothing. Left empty, it is
    // "fake" in development and tests and "deepl" in production - where, with no key, Translate is offered
    // disabled ("Translation isn't set up on this server.") rather than failing. "fake" set by name in
    // production is refused.
    'deepl' => [
        'driver' => env('TEXT_TRANSLATOR', ''),
        'key' => env('DEEPL_API_KEY', ''),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
