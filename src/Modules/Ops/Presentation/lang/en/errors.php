<?php

declare(strict_types=1);

// Ops' error messages, by error type (ops.{key}), and the names of the fields they point at (ops.md §7).
return [
    'inbox_entry_not_found' => [
        'title' => 'Notification Not Found',
        'detail' => 'This notification isn\'t in your list.',
    ],
    'daily_report_not_found' => [
        'title' => 'Report Not Found',
        'detail' => 'This daily report isn\'t in your list.',
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
    // Step 8's reports (§1.10, §7). NOT YET APPROVED by the owner.
    'report_range_refused' => [
        'title' => 'Choose Other Days',
        'detail' => 'A report can\'t cover these days.',
        'rules' => [
            'years' => 'Choose days from 2020 to the end of next year.',
            'too_long' => 'A report covers at most 5 years. Choose fewer days.',
            'searches_too_long' => 'The searches report covers at most 12 months: the search log keeps no more.',
        ],
    ],
    'confirmation_link_invalid' => [
        'title' => 'Link Not Valid',
        'detail' => 'This confirmation link isn\'t valid any more. Sign up again to get a new one.',
    ],
    'sms_country_not_found' => [
        'title' => 'Country Not on the List',
        'detail' => 'Couldn\'t find ":country" on the SMS countries. Reload the list and try again.',
    ],
    'sms_country_taken' => [
        'title' => 'Country Already Listed',
        'detail' => 'Couldn\'t add ":country": it is already on the SMS countries. Change its provider or calling code there.',
    ],
    'calling_code_overlaps' => [
        'title' => 'Calling Code Overlaps',
        'detail' => 'Couldn\'t use the calling code :calling_code: it overlaps :other_code, the code of ":country", so a number\'s country would be unclear. Check the code and try again.',
    ],
    'calling_code_taken' => [
        'title' => 'Calling Code in Use',
        'detail' => 'Couldn\'t use the calling code :calling_code: it is already the code of ":country". Check the code and try again.',
    ],

    'fields' => [
        'after' => 'place in the list',
        // Step 8's reports (§1.10). NOT YET APPROVED by the owner.
        'report' => 'report',
        'from' => 'first day',
        'to' => 'last day',
        'by' => 'grouping (day or month)',
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
        'country_code' => 'country',
        'calling_code' => 'calling code',
        'other_code' => 'other calling code',
        'provider' => 'provider',
        'day' => 'day',
        'file' => 'file',
        'emailed_at' => 'time emailed',
        'next_due_at' => 'next time to send',
        'version' => 'version',
    ],
];
