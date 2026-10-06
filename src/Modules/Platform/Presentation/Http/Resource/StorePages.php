<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use Modules\Platform\Application\Query\ListStores\ListStores;
use Modules\Platform\Application\Query\ListStores\ListStoresHandler;
use Modules\Platform\Application\Query\ListStores\StoreSummary;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Domain\Model\Currency;
use Modules\Platform\Public\Dto\CurrencyDto;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * Platform's store reads, in the shape the screens want (frontend.md 3.5).
 *
 * It decides nothing. Which stores appear, and which of them may be changed, are the handler's
 * answers; what happens here is arrangement and the one conversion this screen needs - basis points
 * into the percentage a person reads.
 */
final readonly class StorePages
{
    public function __construct(
        private Application $app,
        private StoreDirectory $directory,
    ) {}

    /** E1, with E2 on it, and Add Store for whoever may open one. */
    public function list(ListStoresHandler $handler): StoresPage
    {
        $stores = $handler->handle(new ListStores($this->locale()));

        return new StoresPage(
            array_map($this->row(...), $stores),
            self::timezones(),
            $handler->maySwitch(),
            $handler->mayCreate() ? $this->newStoreForm() : null,
        );
    }

    /**
     * Add Store's choices (platform.md §9.7 #3, #4): the currencies no store - on or off - uses, every
     * country with ours first, the time zone of each country with only one, and the place after the
     * last store.
     */
    private function newStoreForm(): NewStoreForm
    {
        $all = $this->directory->stores();
        $taken = array_map(static fn (StoreDto $store): string => $store->currencyCode, $all);
        $free = array_values(array_filter($this->directory->currencies(), static fn (CurrencyDto $currency): bool => ! in_array($currency->code, $taken, true)));
        $positions = array_map(static fn (StoreDto $store): int => $store->position, $all);

        return new NewStoreForm(
            array_map(fn (CurrencyDto $currency): FreeCurrencyRow => new FreeCurrencyRow($currency->code, $currency->name->in($this->locale())), $free),
            StoreCountries::in($this->locale(), array_values(array_unique(array_map(static fn (StoreDto $store): string => $store->countryCode, $all)))),
            StoreCountries::zones(),
            range(0, Currency::MAX_EXPONENT),
            $positions === [] ? 10 : max($positions) + 10,
        );
    }

    private function row(StoreSummary $store): StoreRow
    {
        return new StoreRow(
            $store->id,
            $store->code,
            $this->locale() === 'en' ? $store->nameEn : $store->nameAr,
            $store->nameAr,
            $store->nameEn,
            $store->countryCode,
            $store->currencyCode,
            $store->currencySymbol,
            $store->taxRateBasisPoints,
            self::percent($store->taxRateBasisPoints),
            $store->timezone,
            $store->position,
            $store->editable,
            $store->isActive,
            $store->isBase,
            $store->switchable,
        );
    }

    /**
     * 1500 basis points as "15", and 1550 as "15.5".
     *
     * Written out digit by digit rather than divided, because a rate is money's neighbour: dividing
     * by 100 turns an exact integer into a float, and the trailing noise it picks up would be shown
     * to a person and posted back as their answer.
     */
    public static function percent(int $basisPoints): string
    {
        $whole = intdiv($basisPoints, 100);
        $fraction = $basisPoints % 100;

        if ($fraction === 0) {
            return (string) $whole;
        }

        return $fraction % 10 === 0
            ? $whole.'.'.intdiv($fraction, 10)
            : $whole.'.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Every IANA identifier, in one list.
     *
     * Not translated: a timezone identifier is a name, the same in every language, and the one an
     * administrator recognises. PHP's list is the same list the value object checks against, so a
     * choice made here can never be refused as unknown.
     *
     * @return list<string>
     */
    public static function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
