<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

/**
 * Inventory's words in the language of whoever is reading (inventory.md §4.3.1 "Words"): the screens'
 * reads answer a size's values and the reason a button is disabled already written. Bound to the
 * module's lang files in Infrastructure, as Pricing's are.
 */
interface Words
{
    /**
     * @return 'ar'|'en' the reader's language; Arabic unless it is English (handoff §5.2)
     */
    public function locale(): string;

    /**
     * A line of Inventory's lang files - "admin_stock.reasons.wired" is `inventory::admin_stock.reasons.wired`.
     *
     * @param  array<string, string|int>  $replace
     */
    public function line(string $key, array $replace = []): string;
}
