<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

/**
 * Where Ops' emails leave (ops.md §2.2): Brevo's transactional API, or the application log in
 * development and tests - chosen by configuration (`OPS_EMAIL_DRIVER`). A new provider is a new class
 * and a binding.
 */
interface EmailSender
{
    /**
     * @return string|null the provider's id for the message, when it gives one
     *
     * @throws ProviderFailed when the provider did not take it
     */
    public function send(OutgoingEmail $email): ?string;
}
