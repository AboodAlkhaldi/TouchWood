<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RegisterCustomer;

/**
 * A new customer account in the store the request is in (spec §1.2): email, password, the person's
 * name, the kind of account, the page's language, and acceptance of that store's terms.
 *
 * $ip is the address the form came from, counted against the store's hourly limit. It is empty
 * outside a request (a console command, a test fixture), where there is no connection to limit.
 */
final readonly class RegisterCustomer
{
    public function __construct(
        public string $email,
        public string $password,
        public string $firstName,
        public string $lastName,
        public string $accountType,
        public string $locale,
        public bool $termsAccepted,
        public string $ip = '',
        /** The "offers and news" box, unticked by default (amendment 79). */
        public bool $marketingConsent = false,
        /**
         * The store's Terms and Conditions and Privacy Policy as the form showed them beside the tick, by id and
         * version (flags-2026-11.md S3.4; owner, 2026-11-20): refused unless they are the store's now. None where the
         * form showed none, as for a store with none published.
         */
        public ?string $shownTermsId = null,
        public ?int $shownTermsVersion = null,
        public ?string $shownPrivacyId = null,
        public ?int $shownPrivacyVersion = null,
    ) {}
}
