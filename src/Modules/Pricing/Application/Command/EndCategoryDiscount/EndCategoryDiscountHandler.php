<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\EndCategoryDiscount;

use Carbon\CarbonImmutable;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\DiscountAudit;
use Modules\Pricing\Application\Prices\DiscountCoverage;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\CategoryDiscountNotFound;
use Modules\Pricing\Domain\Exception\NotChangeable;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;

/**
 * **Ending a category discount now** (pricing.md §3, §4.1), under **Manage Category Discounts** in its
 * store: a running one only; its sizes' candidates written again, audited.
 */
final readonly class EndCategoryDiscountHandler
{
    public const string PERMISSION = PricingPermissions::CATEGORY_DISCOUNT_MANAGE;

    public function __construct(
        private PricingChange $change,
        private DiscountCoverage $coverage,
        private CategoryDiscounts $discounts,
        private CandidateWriter $writer,
    ) {}

    public function handle(EndCategoryDiscount $command): void
    {
        $found = $this->discounts->find($command->discountId) ?? throw new CategoryDiscountNotFound;
        $store = $this->change->authorize(self::PERMISSION, $found->storeId);
        $storeId = $store->storeId();

        $this->change->run($storeId, function () use ($command, $storeId, $store): array {
            $now = CarbonImmutable::now()->toDateTimeImmutable();
            $before = $this->discounts->find($command->discountId) ?? throw new CategoryDiscountNotFound;

            if (! $before->running($now)) {
                throw new NotChangeable;
            }

            $after = new CategoryDiscount($before->id, $before->storeId, $before->categoryId, $before->percentBasisPoints, $before->amount, $before->lines, $before->startsAt, $now, $before->alwaysWins, $before->excludedProductIds);
            $this->discounts->save($after);
            $this->writer->refresh($storeId, array_keys($this->coverage->pricedSizes($storeId, [$after])));

            return [null, [DiscountAudit::entry('pricing.category_discount.ended', $before, $after, $store->currencyExponent)]];
        });
    }
}
