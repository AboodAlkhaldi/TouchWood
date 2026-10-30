<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

use Shared\Domain\ValueObject\StoreId;

/**
 * Where the newsletter's subscribers are copied (ops.md §1.9, §2.2): one list per store and language,
 * in Brevo, or the application log in development and tests - chosen by configuration
 * (`OPS_NEWSLETTER_DRIVER`). Newsletters are designed and sent there, not here (round 3 #12 (a)).
 *
 * Every call may be repeated: adding a contact already on the list, or taking out one that is not,
 * changes nothing.
 */
interface NewsletterList
{
    /**
     * Puts the email on the store's list of that language.
     *
     * @throws ProviderFailed
     */
    public function add(string $email, StoreId $store, string $locale): void;

    /**
     * Takes the email off the store's list of that language.
     *
     * @throws ProviderFailed
     */
    public function remove(string $email, StoreId $store, string $locale): void;

    /**
     * Lets the provider email the contact again: someone who unsubscribed in a newsletter, and then
     * agreed again with us - a customer's switch back on, a guest's new confirmed sign-up.
     *
     * @throws ProviderFailed
     */
    public function allow(string $email): void;

    /**
     * Deletes the contact itself, from every list: the person's account was anonymized (§6).
     *
     * @throws ProviderFailed
     */
    public function forget(string $email): void;
}
