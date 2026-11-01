<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Settings;

use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Enums\OnlinePaymentReason;
use Modules\Payments\Public\Enums\PaymentAudience;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Platform\Public\Contracts\SettingsSectionLine;
use Shared\Domain\ValueObject\StoreId;

/**
 * The line at the top of the Payments section of the settings page (payments.md §1.2, §1.9, gap 8.8): how
 * the store shown takes money, online and by transfer, and what is missing while either is off. Both are
 * per store, so a page showing no store has no line.
 */
final readonly class PaymentsSettingsLine implements SettingsSectionLine
{
    public function __construct(
        private PaymentsApi $payments,
    ) {}

    public function line(?StoreId $store): ?string
    {
        if ($store === null) {
            return null;
        }

        return $this->online($store).' · '.($this->payments->bankAccount($store) === null
            ? (string) __('payments::settings.line.bank_transfer.off')
            : (string) __('payments::settings.line.bank_transfer.on'));
    }

    private function online(StoreId $store): string
    {
        $online = $this->payments->availability($store, PaymentAudience::Individual);

        return (string) __('payments::settings.line.online.'.match (true) {
            $online->reason === OnlinePaymentReason::KeyRejected => 'key_rejected',
            ! $online->available => 'off',
            $online->mode === PaymentMode::Test => 'test',
            default => 'on',
        });
    }
}
