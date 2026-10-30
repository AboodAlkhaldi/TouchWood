<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

use Modules\Access\Public\Contracts\PhoneCountries;
use Modules\Ops\Domain\Model\SmsCountry;
use Modules\Ops\Domain\Repository\SmsCountries;

/**
 * The SMS countries as Access asks about them, and as Ops' own SMS find their provider (access.md
 * amendment 90): a number belongs to the listed country whose calling code its digits start with.
 * Calling codes are prefix-free, so one matches at most; the longest wins should two ever do.
 *
 * The list is read once per instance - one query for a page of a thousand customers - and the binding
 * is not shared, so whoever resolves it next reads it again.
 */
final class ListedPhoneCountries implements PhoneCountries
{
    /** @var list<SmsCountry>|null */
    private ?array $listed = null;

    public function __construct(
        private readonly SmsCountries $countries,
    ) {}

    public function accepts(string $phone): bool
    {
        return $this->countryOf($phone) !== null;
    }

    public function countryOf(string $phone): ?SmsCountry
    {
        $this->listed ??= $this->countries->all();
        $found = null;

        foreach ($this->listed as $country) {
            if ($country->serves($phone) && strlen($country->callingCode()) > strlen($found?->callingCode() ?? '')) {
                $found = $country;
            }
        }

        return $found;
    }
}
