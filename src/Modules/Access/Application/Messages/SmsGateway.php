<?php

declare(strict_types=1);

namespace Modules\Access\Application\Messages;

/**
 * Where SMS messages leave. The provider is not chosen yet (owner's decision, 2026-09-18): the `log`
 * driver writes them to the application log; the provider's adapter is added when the owner names
 * it, chosen by configuration (`ACCESS_SMS_DRIVER`).
 */
interface SmsGateway
{
    public function send(string $phone, string $message): void;
}
