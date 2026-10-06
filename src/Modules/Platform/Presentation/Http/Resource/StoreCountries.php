<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Collator;
use DateTimeZone;
use Locale;
use ResourceBundle;

/**
 * Every country a store may be opened in, for the Add Store form (platform.md §9.7 #3): PHP's ICU
 * data without the codes ICU knows that are not ISO countries, named in the panel's language and
 * sorted as that language sorts - the countries we already have a store in first.
 *
 * Access keeps its own list for staff addresses; Platform sits below Access and cannot read it, so
 * the store form builds this one the same way.
 */
final readonly class StoreCountries
{
    /** In ICU's region list but not ISO 3166-1 countries: groupings, reserved and private-use codes. */
    private const array NOT_COUNTRIES = ['AC', 'CP', 'CQ', 'DG', 'EA', 'EU', 'EZ', 'IC', 'QO', 'TA', 'UN', 'XA', 'XB', 'XK', 'ZZ'];

    /**
     * @param  list<string>  $ours  the countries we have a store in, which come first
     * @return list<StoreCountryOption>
     */
    public static function in(string $locale, array $ours = []): array
    {
        $options = [];

        foreach (self::codes() as $code) {
            $name = Locale::getDisplayRegion('-'.$code, $locale);
            $options[] = new StoreCountryOption($code, $name === false || $name === '' ? $code : $name, in_array($code, $ours, true));
        }

        $collator = Collator::create($locale);
        $byName = $collator instanceof Collator
            ? fn (StoreCountryOption $a, StoreCountryOption $b): int => (int) $collator->compare($a->name, $b->name)
            : fn (StoreCountryOption $a, StoreCountryOption $b): int => strcmp($a->name, $b->name);

        usort($options, fn (StoreCountryOption $a, StoreCountryOption $b): int => $a->ours === $b->ours ? $byName($a, $b) : ($a->ours ? -1 : 1));

        return $options;
    }

    /**
     * The time zone of each country that has exactly one, to fill the form's field when the country
     * is chosen. A country with several (the United States, say) is left out, and its zone is
     * picked: PHP lists them alphabetically, so the first could be hours out (the review of P7).
     *
     * @return array<string, string>
     */
    public static function zones(): array
    {
        $zones = [];

        foreach (self::codes() as $code) {
            $all = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $code);

            if (count($all) === 1) {
                $zones[$code] = $all[0];
            }
        }

        return $zones;
    }

    /**
     * @return list<string>
     */
    private static function codes(): array
    {
        static $codes = null;

        if ($codes === null) {
            $codes = [];
            $regions = ResourceBundle::create('en', 'ICUDATA-region')?->get('Countries');

            foreach ($regions instanceof ResourceBundle ? $regions : [] as $code => $name) {
                if (is_string($code) && preg_match('/\A[A-Z]{2}\z/', $code) === 1 && ! in_array($code, self::NOT_COUNTRIES, true)) {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
    }
}
