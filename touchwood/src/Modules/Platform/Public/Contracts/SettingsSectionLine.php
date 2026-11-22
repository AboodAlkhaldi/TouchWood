<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Shared\Domain\ValueObject\StoreId;

/**
 * One line at the top of a module's section of the settings page (platform.md §1.3): what its
 * settings add up to in the store the page shows — B2B's says whether bank transfer is on. Register
 * the class with SettingsSectionLines; it is resolved only when the page is shown.
 */
interface SettingsSectionLine
{
    /**
     * The line, in the language the panel is being read in; null for none.
     *
     * @param  StoreId|null  $store  the store in the panel's header; null when it shows none
     */
    public function line(?StoreId $store): ?string;
}
