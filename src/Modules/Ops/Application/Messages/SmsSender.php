<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

/**
 * Where Ops' SMS leave (ops.md §2.2): the application log until the owner names the provider (Mobily
 * is being checked) - chosen by configuration (`OPS_SMS_DRIVER`).
 */
interface SmsSender
{
    /**
     * @return string|null the provider's id for the message, when it gives one
     *
     * @throws ProviderFailed when the provider did not take it
     */
    public function send(string $phone, string $message): ?string;
}
