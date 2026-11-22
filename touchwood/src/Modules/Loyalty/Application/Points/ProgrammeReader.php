<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Points;

use Modules\Loyalty\Application\Settings\ProgrammeSettings as Keys;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Model\Programme;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * A store's programme as its settings read now (loyalty.md §1.5), with the store's currency and its
 * exponent from Platform. Read when used: earning at delivery, a redemption at checkout.
 */
final readonly class ProgrammeReader
{
    public function __construct(
        private PlatformApi $platform,
    ) {}

    /**
     * @throws InvalidPointsAttribute when Platform has no such store
     */
    public function of(StoreId $store): Programme
    {
        $known = $this->platform->store($store) ?? throw new InvalidPointsAttribute('store', 'no such store');
        $setting = fn (string $key) => $this->platform->setting($key, $store);

        return new Programme(
            enabled: $setting(Keys::ENABLED)->bool(),
            earnPerUnit: $setting(Keys::EARN_PER_UNIT)->int(),
            pointsPerUnitOff: $setting(Keys::POINTS_PER_UNIT_OFF)->int(),
            expiryMonths: $setting(Keys::EXPIRY_MONTHS)->int(),
            minimumRedemption: $setting(Keys::MINIMUM_REDEMPTION)->int(),
            maxRedemptionPercent: $setting(Keys::MAX_REDEMPTION_PERCENT)->int(),
            publicPartial: $setting(Keys::PUBLIC_PARTIAL)->bool(),
            companyPartial: $setting(Keys::COMPANY_PARTIAL)->bool(),
            currencyCode: $known->currencyCode,
            exponent: $known->currencyExponent,
        );
    }
}
