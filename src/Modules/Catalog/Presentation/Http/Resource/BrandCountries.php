<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Collator;
use Locale;
use ResourceBundle;

/**
 * Every country a brand may come from, for the brand form's country picker (catalog.md §1.6, §4.4 S1):
 * PHP's ICU data without the codes ICU knows that are not ISO countries, named in the panel's
 * language and sorted as that language sorts.
 *
 * Access and Platform keep their own lists for their own forms, built the same way (Platform's
 * `StoreCountries`); a module may not reach into another's Presentation, so Catalog keeps this one.
 */
final readonly class BrandCountries
{
    /** In ICU's region list but not ISO 3166-1 countries: groupings, reserved and private-use codes. */
    private const array NOT_COUNTRIES = ['AC', 'CP', 'CQ', 'DG', 'EA', 'EU', 'EZ', 'IC', 'QO', 'TA', 'UN', 'XA', 'XB', 'XK', 'ZZ'];

    /**
     * @return list<CountryOptionData>
     */
    public static function in(string $locale): array
    {
        $options = [];
        $regions = ResourceBundle::create('en', 'ICUDATA-region')?->get('Countries');

        foreach ($regions instanceof ResourceBundle ? $regions : [] as $code => $name) {
            if (is_string($code) && preg_match('/\A[A-Z]{2}\z/', $code) === 1 && ! in_array($code, self::NOT_COUNTRIES, true)) {
                $display = Locale::getDisplayRegion('-'.$code, $locale);
                $options[] = new CountryOptionData($code, $display === false || $display === '' ? $code : $display, false);
            }
        }

        $collator = Collator::create($locale);
        usort($options, $collator instanceof Collator
            ? fn (CountryOptionData $a, CountryOptionData $b): int => (int) $collator->compare($a->name, $b->name)
            : fn (CountryOptionData $a, CountryOptionData $b): int => strcmp($a->name, $b->name));

        return $options;
    }
}
