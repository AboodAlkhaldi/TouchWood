<?php

declare(strict_types=1);

namespace Modules\Access\Application\Security;

use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Access\Public\Contracts\PhoneCountries;

/**
 * Whether SMS reaches a phone - its country on the SMS countries list (amendment 90; owner,
 * 2026-10-29) - asked wherever an account phone is entered and before every code is sent. The address
 * book's recipient phones are not asked: they receive no code.
 */
final readonly class ServedPhones
{
    public function __construct(
        private PhoneCountries $countries,
    ) {}

    /**
     * Refused with its own words, on the phone field: a number from a country SMS does not reach
     * would never receive its code.
     *
     * @throws InvalidAccessAttribute
     */
    public function require(PhoneNumber $phone): void
    {
        if (! $this->countries->accepts($phone->value)) {
            throw new InvalidAccessAttribute('phone', 'its country is not on the SMS countries list', 'phone_country');
        }
    }

    /**
     * A saved phone whose country was taken off the list counts as none where a code would go to it:
     * a Super Admin enters a new number at sign-in, a customer reads as unverified (amendment 90).
     */
    public function reaches(?PhoneNumber $phone): bool
    {
        return $phone !== null && $this->countries->accepts($phone->value);
    }
}
