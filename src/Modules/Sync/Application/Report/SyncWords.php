<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

/**
 * Sync's words in the language of whoever is reading (sync.md §4.1.1; pricing.md §4.3.1 "Words"): the
 * screens' reads answer a failure's reason, a size's values and why a button is disabled already
 * written. Bound to the module's lang files in Infrastructure, as Pricing's and Inventory's are.
 */
interface SyncWords
{
    /**
     * @return 'ar'|'en' the reader's language; Arabic unless it is English (handoff §5.2)
     */
    public function locale(): string;

    /**
     * A line of Sync's lang files - "admin_report.failures.UNWIRED" is `sync::admin_report.failures.UNWIRED`.
     *
     * @param  array<string, string|int>  $replace
     * @param  'ar'|'en'|null  $locale  another language than the reader's - English for the audit log's sentence
     */
    public function line(string $key, array $replace = [], ?string $locale = null): string;
}
