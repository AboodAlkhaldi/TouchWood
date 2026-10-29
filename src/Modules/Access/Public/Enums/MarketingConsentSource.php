<?php

declare(strict_types=1);

namespace Modules\Access\Public\Enums;

/**
 * How a customer's marketing consent last changed (amendment 79). Staff never change it.
 */
enum MarketingConsentSource: string
{
    /** The box at registration. */
    case Registration = 'REGISTRATION';

    /** The switch in their account. */
    case Account = 'ACCOUNT';

    /** The unsubscribe link in a marketing email (Ops). */
    case UnsubscribeLink = 'UNSUBSCRIBE_LINK';

    /** The newsletter provider told Ops they unsubscribed there (Brevo). */
    case NewsletterProvider = 'NEWSLETTER_PROVIDER';

    /**
     * @return list<self> the sources Ops may withdraw consent from — never one that gives it
     */
    public static function withdrawals(): array
    {
        return [self::UnsubscribeLink, self::NewsletterProvider];
    }
}
