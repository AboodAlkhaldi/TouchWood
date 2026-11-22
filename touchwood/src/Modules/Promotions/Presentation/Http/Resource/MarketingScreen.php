<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Promotions\Application\PromotionsPermissions;
use Shared\Application\Unauthorized;

/**
 * What every Marketing screen shares (promotions.md §4.1): the store shown - the one asked for, else
 * the first that is on among those where the reader holds a Marketing job - the stores offered, the
 * page's language, and moments and cursors as the pages send and take them.
 */
final readonly class MarketingScreen
{
    /** Every job that opens the Marketing screens of a store (§3). */
    public const array JOBS = [
        PromotionsPermissions::VIEW,
        PromotionsPermissions::COUPON_MANAGE,
        PromotionsPermissions::SEGMENT_MANAGE,
        PromotionsPermissions::GIFT_MANAGE,
    ];

    private const string CURSOR = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private Application $app,
        private StoreChoices $choices,
    ) {}

    /**
     * The store shown and the stores offered.
     *
     * @return array{0: StoreDto, 1: list<StoreDto>}
     *
     * @throws Unauthorized
     */
    public function store(?string $code): array
    {
        $offered = $this->choices->forJobs(...self::JOBS);
        $chosen = null;

        foreach ($offered as $store) {
            if ($code === null ? $store->isActive : strtolower($store->code) === strtolower(trim($code))) {
                $chosen = $store;
                break;
            }
        }

        // A Super Admin with every store off still opens one.
        $chosen ??= $code === null ? ($offered[0] ?? null) : null;

        return [$chosen ?? throw new Unauthorized(PromotionsPermissions::VIEW), $offered];
    }

    /**
     * @return array{storeCode: string, storeName: string, storeIsActive: bool, storeTimezone: string}
     */
    public function shown(StoreDto $store): array
    {
        return [
            'storeCode' => $store->code,
            'storeName' => $store->name->in($this->locale()),
            'storeIsActive' => $store->isActive,
            'storeTimezone' => $store->timezone,
        ];
    }

    /**
     * @param  list<StoreDto>  $stores
     * @return list<StoreOptionData>
     */
    public function options(array $stores): array
    {
        return array_map(fn (StoreDto $store): StoreOptionData => new StoreOptionData($store->code, $store->name->in($this->locale()), $store->isActive), $stores);
    }

    /** The page's language: Arabic unless English. */
    public function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }

    /** A name in the page's language. */
    public function name(string $ar, ?string $en): string
    {
        return $this->locale() === 'en' && $en !== null && $en !== '' ? $en : $ar;
    }

    /** A moment as the screens take it: ISO 8601 in UTC, to the millisecond, written in the store's zone by `Time`. */
    public static function moment(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.vP');
    }

    /** A next-page cursor's moment, to the microsecond the rows keep. */
    public static function cursor(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format(self::CURSOR);
    }

    /** A cursor's moment from the address; one that is not a moment reads as none. */
    public static function before(?string $at): ?DateTimeImmutable
    {
        if ($at === null) {
            return null;
        }

        $moment = DateTimeImmutable::createFromFormat(self::CURSOR, $at);

        return $moment === false ? null : $moment;
    }
}
