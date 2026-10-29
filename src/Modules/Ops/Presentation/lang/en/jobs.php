<?php

declare(strict_types=1);

// What Ops' queued work is called on the failed jobs screen (frontend.md E7).
return [
    'prune_ops_log' => 'Removing inbox entries, sent messages and handled events older than twelve months',
    'prune_written_messages' => 'Removing staff messages and notices older than twelve months, and unconfirmed newsletter sign-ups',
    'start_due_notices' => 'Starting the offer notices whose time has come',
    'send_broadcast_batch' => 'Sending a notice or a staff message to a group of customers',
    'send_broadcast_email' => 'Sending a notice or a staff message by email to one customer',
    'sync_subscriber' => 'Updating a newsletter subscriber at the newsletter provider',
    'remove_newsletter_contact' => 'Removing an email from a newsletter list at the newsletter provider',
    'withdraw_marketing_consent' => 'Switching off a customer\'s offers and news after they unsubscribed',
    'refresh_newsletter_subscription' => 'Updating a customer\'s place on the newsletter list',
    'forget_anonymized_customer' => 'Removing a deleted customer\'s notifications, messages and newsletter subscription',
];
