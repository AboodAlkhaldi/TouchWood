<?php

declare(strict_types=1);

// What Ops' queued work is called on the failed jobs screen (frontend.md E7).
return [
    'send_daily_report_email' => 'Making the daily report to email at the time its reader chose',
    'deliver_daily_report' => 'Emailing a daily report',
    'prune_daily_reports' => 'Removing daily reports older than twelve months',
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
    'tell_customer_about_order' => 'Telling a customer what happened to their order',
    'tell_customer_about_return' => 'Telling a customer what happened to their return',
    'tell_customer_about_points' => 'Telling a customer their points changed',
    'tell_customer_about_feedback' => 'Telling a customer what happened to their question or review',
    'alert_staff' => 'Alerting a store\'s staff',
    'send_event_email' => 'Emailing one person a message or an alert',
    'send_event_sms' => 'Sending a super admin the urgent SMS about the payment key',
    'remind_expiring_points' => 'Reminding the customers whose points expire in 7 days',
];
