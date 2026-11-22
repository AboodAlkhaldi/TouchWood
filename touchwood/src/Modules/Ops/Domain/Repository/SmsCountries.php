<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Repository;

use Modules\Ops\Domain\Model\SmsCountry;

/**
 * The SMS countries (`ops.sms_countries`; access.md amendment 90). Every change takes the list's one
 * lock first (`lock`), so two changes never interleave: the list is short and rarely changed, and a
 * calling code is unique across it.
 */
interface SmsCountries
{
    /** Held until the transaction ends. */
    public function lock(): void;

    /**
     * @return list<SmsCountry> by country code
     */
    public function all(): array;

    public function byCode(string $countryCode): ?SmsCountry;

    public function byCallingCode(string $callingCode): ?SmsCountry;

    /**
     * Another listed country whose calling code overlaps this one (SmsCountry::overlaps), if any.
     */
    public function overlapping(SmsCountry $country): ?SmsCountry;

    public function add(SmsCountry $country): void;

    public function update(SmsCountry $country): void;

    public function remove(string $countryCode): void;
}
