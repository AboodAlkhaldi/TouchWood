<?php

declare(strict_types=1);

namespace Modules\Access\Public\Contracts;

/**
 * Whether SMS reaches a phone (amendment 87): its country - its calling code - is on the SMS
 * countries list the Super Admins keep. Access asks it wherever an account phone is entered and
 * before every code is sent; Ops keeps the list and binds this, as it binds SecurityMessages, so
 * Access never depends on Ops.
 *
 * A number from a country not on the list is refused when it is entered. One already saved stays,
 * and is treated as no phone where a code would go to it: a Super Admin enters a new number at sign-in,
 * a customer reads as unverified until they change it (owner, 2026-10-29).
 */
interface PhoneCountries
{
    /**
     * @param  string  $phone  in E.164, as PhoneNumber keeps it: +966501234567
     */
    public function accepts(string $phone): bool;
}
