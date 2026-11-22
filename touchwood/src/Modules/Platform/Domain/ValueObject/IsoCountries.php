<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\ValueObject;

use Locale;
use ResourceBundle;

/**
 * Every ISO 3166-1 country PHP's ICU data knows, and its names: the store form's list (platform.md
 * §9.7 #3) and the address countries (stage 7 (a)) are both made of it.
 */
final class IsoCountries
{
    /** In ICU's region list but not ISO 3166-1 countries: groupings, reserved and private-use codes. */
    private const array NOT_COUNTRIES = ['AC', 'CP', 'CQ', 'DG', 'EA', 'EU', 'EZ', 'IC', 'QO', 'TA', 'UN', 'XA', 'XB', 'XK', 'ZZ'];

    /**
     * @return list<string>
     */
    public static function codes(): array
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

    public static function exists(string $code): bool
    {
        return in_array($code, self::codes(), true);
    }

    /**
     * The country's name in a language, or its code when ICU has none.
     */
    public static function name(string $code, string $locale): string
    {
        $name = Locale::getDisplayRegion('-'.$code, $locale);

        return $name === false || $name === '' ? $code : $name;
    }
}
