<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use Modules\Platform\Application\Query\ListStores\ListStores;
use Modules\Platform\Application\Query\ListStores\ListStoresHandler;
use Modules\Platform\Application\Query\ListStores\StoreSummary;

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
    ) {}

    /** E1, with E2 on it. */
    public function list(ListStoresHandler $handler): StoresPage
    {
        $stores = $handler->handle(new ListStores($this->locale()));

        return new StoresPage(
            array_map($this->row(...), $stores),
            self::timezones(),
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
