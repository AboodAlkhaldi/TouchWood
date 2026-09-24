<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Collator;
use Locale;
use Modules\Access\Domain\ValueObject\CountryCode;

/**
 * Every country, for the screens that ask a staff member where they live: their own account
 * (frontend.md §3.2, B1) and the invitation that creates them (§3.3, C3).
 *
 * Named in the language the panel is being read in and sorted the way that language sorts —
 * `strcmp` would put the Arabic list in an order no Arabic reader recognises.
 */
final readonly class Countries
{
    /**
     * @param  list<string>  $ours  the countries we have a store in, which come first
     * @return list<CountryOption>
     */
    public static function in(string $locale, array $ours = []): array
    {
        $options = [];

        foreach (CountryCode::all() as $code) {
            // ICU is where the codes came from, so it has a name for every one of them. If it ever
            // does not, the code itself is shown: a list with "ZZ" in it is odd, and a list with a
            // blank row in it is a country nobody can choose.
            $name = Locale::getDisplayRegion('-'.$code, $locale);
            $options[] = new CountryOption($code, $name === false ? $code : $name, in_array($code, $ours, true));
        }

        $collator = Collator::create($locale);

        $byName = $collator instanceof Collator
            ? fn (CountryOption $a, CountryOption $b): int => (int) $collator->compare($a->name, $b->name)
            : fn (CountryOption $a, CountryOption $b): int => strcmp($a->name, $b->name);

        // Ours first, each group still sorted the way the language sorts. They stay in the long
        // list as well: somebody looking for Saudi Arabia under S should find it there.
        usort($options, fn (CountryOption $a, CountryOption $b): int => $a->ours === $b->ours
            ? $byName($a, $b)
            : ($a->ours ? -1 : 1));

        return $options;
    }
}
