<?php

declare(strict_types=1);

namespace Modules\Access\Domain\ValueObject;

use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use ResourceBundle;

/**
 * Any country of the world, as an ISO 3166-1 alpha-2 code: staff can live anywhere (owner's
 * decision, 2026-09-19). The list is PHP's intl data (ICU) without the codes ICU knows that are not
 * ISO countries.
 */
final readonly class CountryCode
{
    /** In ICU's region list but not ISO 3166-1 countries: groupings, reserved and private-use codes. */
    private const array NOT_COUNTRIES = ['AC', 'CP', 'CQ', 'DG', 'EA', 'EU', 'EZ', 'IC', 'QO', 'TA', 'UN', 'XA', 'XB', 'XK', 'ZZ'];

    private function __construct(
        public string $value,
    ) {}

    public static function of(string $value): self
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::all(), true)) {
            throw new InvalidAccessAttribute('country', 'not a country code');
        }

        return new self($value);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
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
