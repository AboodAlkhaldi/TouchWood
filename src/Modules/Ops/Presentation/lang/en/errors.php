<?php

declare(strict_types=1);

// Ops' error messages, by error type (ops.{key}), and the names of the fields they point at (ops.md §7).
return [
    'inbox_entry_not_found' => [
        'title' => 'Notification Not Found',
        'detail' => 'This notification isn\'t in your list.',
    ],
    'invalid_ops_input' => [
        'title' => 'Check the Details',
        'detail' => 'The :attribute isn\'t valid. Check it and try again.',
    ],
    'notice_not_found' => [
        'title' => 'Notice Not Found',
        'detail' => 'This notice isn\'t in the list.',
    ],
    'notice_already_started' => [
        'title' => 'Notice Can\'t Be Changed',
        'detail' => 'This notice has started sending or was cancelled, so it can\'t be changed any more.',
    ],
    'subscriber_not_found' => [
        'title' => 'Subscriber Not Found',
        'detail' => 'This subscriber isn\'t in the list.',
    ],
    'subscribe_in_your_account' => [
        'title' => 'Use Your Account',
        'detail' => 'This email belongs to an account. Sign in and switch on offers and news in your account.',
    ],
    'confirmation_link_invalid' => [
        'title' => 'Link Not Valid',
        'detail' => 'This confirmation link isn\'t valid any more. Sign up again to get a new one.',
    ],

    'fields' => [
        'after' => 'place in the list',
        'entry' => 'notification',
        'kind' => 'kind',
        'title_ar' => 'Arabic title',
        'title_en' => 'English title',
        'line_ar' => 'Arabic line',
        'line_en' => 'English line',
        'link' => 'link',
        'person' => 'person',
        'channel' => 'channel',
        'provider_id' => 'provider id',
        'body_ar' => 'Arabic text',
        'body_en' => 'English text',
        'channels' => 'channels',
        'send_at' => 'sending time',
        'store' => 'store',
        'audience' => 'audience',
        'customer' => 'customer',
        'email' => 'email',
        'locale' => 'language',
        'token' => 'confirmation link',
    ],
];
