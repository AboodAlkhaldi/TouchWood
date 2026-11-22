<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Collator;
use Locale;
use ResourceBundle;

/**
 * Every country Add Country… may offer (ops.md §4.15 O10), sorted the way the page's language sorts: ICU's
 * two-letter regions, less its groupings and reserved codes. The same list as Platform's `IsoCountries`, which
 * Ops may not read, so `SmsCountriesScreenTest` compares the two.
 */
final class SmsCountryOptions
{
    private const array NOT_COUNTRIES = ['AC', 'CP', 'CQ', 'DG', 'EA', 'EU', 'EZ', 'IC', 'QO', 'TA', 'UN', 'XA', 'XB', 'XK', 'ZZ'];

    /** @return list<SmsCountryOptionData> */
    public static function in(string $locale): array
    {
        $options = [];
        $regions = ResourceBundle::create('en', 'ICUDATA-region')?->get('Countries');

        foreach ($regions instanceof ResourceBundle ? $regions : [] as $code => $name) {
            if (is_string($code) && preg_match('/\A[A-Z]{2}\z/', $code) === 1 && ! in_array($code, self::NOT_COUNTRIES, true)) {
                $shown = Locale::getDisplayRegion('-'.$code, $locale);
                $options[] = new SmsCountryOptionData($code, $shown === false ? $code : $shown);
            }
        }

        $collator = Collator::create($locale);

        usort($options, static fn (SmsCountryOptionData $a, SmsCountryOptionData $b): int => $collator instanceof Collator
            ? (int) $collator->compare($a->name, $b->name)
            : strcmp($a->name, $b->name));

        return $options;
    }
}
