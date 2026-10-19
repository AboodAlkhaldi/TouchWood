<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

/**
 * Pricing's words in the language of whoever is reading (pricing.md §4.3.1 "Words"): the screens' reads
 * answer some text already written - an amount with its currency, a sale's short description for Sync's
 * report, a reason a button is disabled. Bound to the module's lang files in Infrastructure.
 */
interface Words
{
    /**
     * @return 'ar'|'en' the reader's language; Arabic unless it is English (handoff §5.2)
     */
    public function locale(): string;

    /**
     * A line of Pricing's lang files - "names.one_size" is `pricing::names.one_size` - with its values.
     *
     * @param  array<string, string|int>  $replace
     */
    public function line(string $key, array $replace = []): string;
}
