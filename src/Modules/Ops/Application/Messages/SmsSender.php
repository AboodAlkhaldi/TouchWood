<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

/**
 * One SMS provider (ops.md §2.2): the application log until the owner names a real one (Mobily is
 * being checked). Which one sends is chosen per number, by its country (SmsSenders; access.md
 * amendment 87).
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
