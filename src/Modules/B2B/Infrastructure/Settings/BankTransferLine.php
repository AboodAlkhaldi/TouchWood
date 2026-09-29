<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Settings;

use Modules\B2B\Application\Settings\StoreBankAccount;
use Modules\Platform\Public\Contracts\SettingsSectionLine;
use Shared\Domain\ValueObject\StoreId;

/**
 * The line at the top of the Companies section of the settings page (b2b.md §2.3, amendment 13(c)):
 * whether bank transfer is on in the store shown, and, while it is temporarily off, what turns it on.
 * The bank settings are per store, so a page showing no store has no line.
 */
final readonly class BankTransferLine implements SettingsSectionLine
{
    public function __construct(
        private StoreBankAccount $bankAccount,
    ) {}

    public function line(?StoreId $store): ?string
    {
        if ($store === null) {
            return null;
        }

        return $this->bankAccount->for($store) === null
            ? (string) __('b2b::settings.bank_transfer.off')
            : (string) __('b2b::settings.bank_transfer.on');
    }
}
