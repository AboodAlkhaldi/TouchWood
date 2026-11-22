<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Settings;

use Modules\Payments\Public\Dto\BankAccountDto;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * A store's bank account, the one reader of the three settings (amendments 12(b), 13(c)). **Bank
 * transfer is on only while all three are filled in**: an empty one means "not set yet", and then
 * there is no account — bank transfer is temporarily off, and a company pays through staff.
 */
final readonly class StoreBankAccount
{
    public function __construct(
        private PlatformApi $platform,
    ) {}

    public function for(StoreId $store): ?BankAccountDto
    {
        $iban = $this->platform->setting(BankAccountSettings::IBAN, $store)->string();
        $bank = $this->platform->setting(BankAccountSettings::BANK, $store)->string();
        $holder = $this->platform->setting(BankAccountSettings::HOLDER, $store)->string();

        return $iban === '' || $bank === '' || $holder === '' ? null : new BankAccountDto($iban, $bank, $holder);
    }
}
