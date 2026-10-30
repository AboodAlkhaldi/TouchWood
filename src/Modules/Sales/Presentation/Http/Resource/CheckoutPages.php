<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use InvalidArgumentException;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\AddressDto;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MoneyData;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Sales\Application\Command\StartCheckout\StartCheckout;
use Modules\Sales\Application\Command\StartCheckout\StartCheckoutHandler;
use Modules\Sales\Application\Query\ViewOwnOrder\ViewOwnOrder;
use Modules\Sales\Application\Query\ViewOwnOrder\ViewOwnOrderHandler;
use Modules\Sales\Domain\Exception\WayToPayNotOffered;
use Modules\Sales\Public\Enums\OrderAudience;
use Modules\Sales\Public\Enums\WayToPay;
use Shared\Application\ActorContext;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * S5, S6 - checkout and the order placed (sales.md §4.1): the quote `StartCheckout` works out for what the page
 * chose - the address (the default first), the points, the way to pay - and the order `ViewOwnOrder` reads
 * after. Every amount written by the server in the store's own currency (Platform's `MoneyData`).
 */
final readonly class CheckoutPages
{
    public function __construct(
        private PlatformApi $platform,
        private AccessApi $access,
        private B2BApi $b2b,
        private CatalogApi $catalog,
        private ActorContext $actors,
    ) {}

    /**
     * @throws Unauthorized|DomainError
     */
    public function checkout(string $storeId, string $locale, ?string $addressId, bool $usePoints, ?int $pointsAsked, ?string $way, StartCheckoutHandler $start): CheckoutPage
    {
        $customerId = $this->actors->current()->id ?? throw new Unauthorized(StartCheckoutHandler::PERMISSION);
        $addresses = $this->access->addresses($customerId, $storeId);
        $usable = array_values(array_filter($addresses, static fn (AddressDto $address): bool => $address->isComplete));
        $chosen = null;

        foreach ($usable as $address) {
            if ($address->id === $addressId) {
                $chosen = $address;
            }
        }

        // The default first, else the newest (Access lists them so).
        $chosen ??= $usable[0] ?? null;
        $rows = array_map(static fn (AddressDto $address): CheckoutAddressData => new CheckoutAddressData(
            $address->id, $address->label, $address->recipientName, $address->formatted, $address->isDefault, $address->isComplete,
        ), $addresses);
        // The one phone delivery uses: the account's, once verified (access.md amendment 89).
        $account = $this->access->customer($customerId);
        $phone = $account?->phoneVerified === true ? $account->phone : null;

        if ($chosen === null) {
            return new CheckoutPage(null, $rows, null, OrderAudience::Public->value, null, null, null, false, null, 0, 0, null, null, [], null, null, null, null, null, null, null, null, null, 0, null, null, null, 0, $phone);
        }

        // A company chooses its way, nothing chosen first (R2-14): until it does, the quote is worked out with
        // staff's contact, which changes no amount.
        $asked = WayToPay::tryFrom((string) $way);
        // A way the account is not offered (an old address, the bank account since emptied) is no choice made.
        try {
            $quote = $start->handle(new StartCheckout($storeId, $chosen->id, $usePoints, $pointsAsked, ($asked ?? WayToPay::StaffContact)->value));
        } catch (WayToPayNotOffered) {
            $asked = null;
            $quote = $start->handle(new StartCheckout($storeId, $chosen->id, $usePoints, $pointsAsked, WayToPay::StaffContact->value));
        }
        $ways = array_map(static fn (WayToPay $each): string => $each->value, $quote->waysToPay);
        $wayChosen = count($ways) === 1 ? $ways[0] : ($asked !== null && in_array($asked->value, $ways, true) ? $asked->value : null);
        $store = $this->platform->store(StoreId::fromString($storeId));
        $exponent = self::exponent($quote->totals->orderTotal, $store);
        $money = fn (Money $amount): MoneyData => self::money($amount, $store, $exponent, $locale);
        $some = fn (Money $amount): ?MoneyData => $amount->isZero() ? null : $money($amount);
        $bank = in_array(WayToPay::BankTransfer->value, $ways, true) ? $this->b2b->bankAccount($storeId) : null;
        $gift = $quote->giftVariantId === null ? null : ($this->catalog->variantBriefs([$quote->giftVariantId])[$quote->giftVariantId] ?? null);
        $totals = $quote->totals;

        return new CheckoutPage(
            $quote->id,
            $rows,
            $chosen->id,
            $quote->audience->value,
            $quote->couponCode,
            $locale === 'en' ? ($quote->couponNameEn ?? $quote->couponNameAr) : ($quote->couponNameAr ?? $quote->couponNameEn),
            $quote->couponRefusal,
            $quote->usePoints,
            $quote->pointsAsked,
            $quote->pointsUsed,
            $quote->pointsMostUsable,
            $quote->pointsMode,
            $quote->pointsRefusal,
            $ways,
            $wayChosen,
            $bank === null ? null : new BankData($bank->iban, $bank->bank, $bank->holder),
            $money($totals->grossSubtotal),
            $some($totals->grossSubtotal->subtract($totals->netSubtotal)),
            $some($totals->couponDiscount),
            $some($totals->pointsDiscount),
            $money($totals->shipping),
            $quote->shippingRuleFreeAbove->isZero() ? null : $money($quote->shippingRuleFreeAbove),
            $money($totals->vat),
            $totals->taxRateBasisPoints,
            $money($totals->orderTotal),
            $gift === null ? null : ($locale === 'en' ? ($gift->productNameEn ?? $gift->productNameAr) : $gift->productNameAr),
            $gift === null ? null : $money(Money::zero($totals->orderTotal->currencyCode)),
            $quote->earningPreview,
            $phone,
        );
    }

    /**
     * @throws Unauthorized|DomainError
     */
    public function placed(string $number, string $locale, ViewOwnOrderHandler $view): PlacedPage
    {
        $order = $view->handle(new ViewOwnOrder($number));
        $store = $this->platform->store(StoreId::fromString($order->storeId));
        $bank = $order->wayToPay === WayToPay::BankTransfer ? $this->b2b->bankAccount($order->storeId) : null;

        return new PlacedPage(
            $order->number,
            self::money($order->total, $store, $order->exponent, $locale),
            $order->wayToPay->value,
            $bank === null ? null : new BankData($bank->iban, $bank->bank, $bank->holder),
            $order->transferDocuments,
            $order->mayUploadTransferDocument,
        );
    }

    private static function exponent(Money $amount, ?StoreDto $store): int
    {
        return $store !== null && $store->currencyCode === $amount->currencyCode ? $store->currencyExponent : 2;
    }

    /** An amount in the store's currency: its sign while the store sells in it, else its letters. */
    private static function money(Money $amount, ?StoreDto $store, int $exponent, string $locale): MoneyData
    {
        try {
            return $store === null ? throw new InvalidArgumentException('No store.') : MoneyData::of($amount, $store, $locale === 'en' ? 'en' : 'ar');
        } catch (InvalidArgumentException) {
            return new MoneyData($amount->format($exponent).' '.$amount->currencyCode, $amount->minorUnits);
        }
    }
}
