<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListSmsCountries;

use Collator;
use Locale;
use Modules\Ops\Application\Messages\SmsSenders;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Model\SmsCountry;
use Modules\Ops\Domain\Repository\SmsCountries;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The SMS countries page (access.md amendment 90): Super Admins only, as the changes are. The names are
 * PHP's (`Locale::getDisplayRegion`), so nothing but the code is stored.
 */
final readonly class ListSmsCountriesHandler
{
    public const string PERMISSION = OpsPermissions::SMS_COUNTRIES_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private SmsCountries $countries,
        private SmsSenders $senders,
    ) {}

    public function handle(ListSmsCountries $query): SmsCountriesList
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $rows = array_map(static fn (SmsCountry $country): SmsCountryRow => new SmsCountryRow(
            $country->countryCode,
            new TranslatedTextDto(self::name($country->countryCode, 'ar'), self::name($country->countryCode, 'en')),
            $country->callingCode(),
            $country->provider(),
        ), $this->countries->all());

        // Alphabetical in the page's language, as that language sorts.
        $collator = Collator::create($query->locale);
        usort($rows, static fn (SmsCountryRow $a, SmsCountryRow $b): int => $collator instanceof Collator
            ? (int) $collator->compare($a->name->in($query->locale), $b->name->in($query->locale))
            : strcmp($a->name->in($query->locale), $b->name->in($query->locale)));

        return new SmsCountriesList($rows, $this->senders->names());
    }

    private static function name(string $code, string $locale): string
    {
        $name = Locale::getDisplayRegion('-'.$code, $locale);

        return $name === false || $name === '' ? $code : $name;
    }
}
