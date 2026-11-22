<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

use Modules\Ops\Domain\Exception\CodesUnavailable;

/**
 * One provider of codes (ops.md §2.2, §9.8 point 1): the application log in development and tests, or WhatsApp.
 * Which one sends is chosen per number, by its country on the Code Countries list (access.md amendments 90, 98).
 *
 * A code is not a text: WhatsApp allows codes only in its authentication template, whose words Meta fixes, so the
 * provider is given the code, its language and its purpose, and not a sentence.
 */
interface CodeSender
{
    /**
     * An attribute is not inherited: every adapter marks its parameters itself (BrevoFailuresTest reads them).
     *
     * @param  string  $locale  "ar" or "en"
     * @return string|null the provider's id for the message, when it gives one
     *
     * @throws ProviderFailed when the provider did not take it
     * @throws CodesUnavailable when the provider is not set up, or refused the setup, so no code can go at all
     */
    public function sendCode(#[\SensitiveParameter] string $phone, string $locale, CodePurpose $purpose, #[\SensitiveParameter] string $code): ?string;

    /**
     * One of the two critical alerts (point 9): the provider's template of that alert in the person's language.
     * `$text` is the alert's own words, which the `log` driver writes; a template has fixed words of its own.
     *
     * @param  string  $locale  "ar" or "en"
     * @return string|null the provider's id for the message, when it gives one
     *
     * @throws ProviderFailed when the provider did not take it
     */
    public function sendAlert(#[\SensitiveParameter] string $phone, string $locale, WhatsAppAlert $alert, #[\SensitiveParameter] string $text): ?string;
}
