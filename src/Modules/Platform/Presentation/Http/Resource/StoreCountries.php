<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Collator;
use DateTimeZone;
use Modules\Platform\Domain\ValueObject\IsoCountries;

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
    /**
     * @param  list<string>  $ours  the countries we have a store in, which come first
     * @return list<StoreCountryOption>
     */
    public static function in(string $locale, array $ours = []): array
    {
        $options = [];

        foreach (IsoCountries::codes() as $code) {
            $options[] = new StoreCountryOption($code, IsoCountries::name($code, $locale), in_array($code, $ours, true));
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

        foreach (IsoCountries::codes() as $code) {
            $all = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $code);

            if (count($all) === 1) {
                $zones[$code] = $all[0];
            }
        }

        return $zones;
    }
}
