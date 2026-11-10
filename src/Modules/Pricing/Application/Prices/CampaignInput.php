<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use DateTimeImmutable;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\PercentOutOfRange;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Exception\WindowInvalid;
use Modules\Pricing\Domain\ValueObject\Campaign;
use Modules\Pricing\Domain\ValueObject\CampaignTarget;
use Modules\Pricing\Domain\ValueObject\DiscountLines;
use Modules\Pricing\Public\Dto\CampaignPriceSetDto;
use Modules\Pricing\Public\Dto\CampaignTargetDto;
use Modules\Pricing\Public\Enums\CampaignTargetKind;

/**
 * A campaign run's prices as Content hands them (pricing.md §9.6 #1, #8), read into a `Campaign` with
 * nothing looked up - what the shape alone refuses, before the store's lock: the run's id and every id
 * an id (kept in lower case, as every id is); an end after the start; each target a percentage from
 * 0.01 to 99.99 or, a size only, a price above 0 in the store's currency, never both and never named
 * twice; a product left out named once.
 */
final class CampaignInput
{
    /**
     * @throws InvalidPricingInput
     */
    public static function runId(string $runId): string
    {
        $id = strtolower(trim($runId));

        return self::isId($id) ? $id : throw new InvalidPricingInput('campaign');
    }

    /**
     * @throws InvalidPricingInput|WindowInvalid|PercentOutOfRange|PriceNotPositive
     */
    public static function campaign(StoreDto $store, string $runId, CampaignPriceSetDto $prices, DateTimeImmutable $start, DateTimeImmutable $end): Campaign
    {
        if ($end <= $start) {
            throw new WindowInvalid;
        }

        $targets = [];

        foreach ($prices->targets as $given) {
            $target = self::target($store, $given);
            $key = $target->kind->value.'|'.$target->id;

            if (isset($targets[$key])) {
                throw new InvalidPricingInput('target');
            }

            $targets[$key] = $target;
        }

        $leftOut = [];

        foreach ($prices->leftOut as $productId) {
            $id = strtolower(trim($productId));
            $leftOut[self::isId($id) ? $id : throw new InvalidPricingInput('left_out')] = true;
        }

        $leftOut = array_map('strval', array_keys($leftOut));
        sort($leftOut);

        return new Campaign(self::runId($runId), $store->id, DiscountLines::from($prices->lines->value), $start, $end, array_values($targets), $leftOut);
    }

    /**
     * @throws InvalidPricingInput|PercentOutOfRange|PriceNotPositive
     */
    private static function target(StoreDto $store, CampaignTargetDto $given): CampaignTarget
    {
        $id = strtolower(trim($given->id));

        if (! self::isId($id) || ($given->percentBasisPoints === null) === ($given->amount === null)) {
            throw new InvalidPricingInput('target');
        }

        if ($given->percentBasisPoints !== null && ($given->percentBasisPoints < 1 || $given->percentBasisPoints > 9999)) {
            throw new PercentOutOfRange;
        }

        if ($given->amount !== null) {
            // A fixed price is per size (§9.6 #1), in the store's own currency.
            if ($given->kind !== CampaignTargetKind::Size || $given->amount->currencyCode !== $store->currencyCode) {
                throw new InvalidPricingInput('amount');
            }

            if (! $given->amount->isPositive()) {
                throw new PriceNotPositive;
            }
        }

        return new CampaignTarget($given->kind, $id, $given->percentBasisPoints, $given->amount);
    }

    private static function isId(string $id): bool
    {
        return preg_match('/\A[0-9a-z]{26}\z/', $id) === 1;
    }
}
