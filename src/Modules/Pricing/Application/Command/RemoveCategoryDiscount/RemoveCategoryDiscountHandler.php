<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\RemoveCategoryDiscount;

use Carbon\CarbonImmutable;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\DiscountAudit;
use Modules\Pricing\Application\Prices\DiscountCoverage;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\CategoryDiscountNotFound;
use Modules\Pricing\Domain\Exception\NotChangeable;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;

/**
 * **Removing a category discount** (pricing.md §3, §4.1), under **Manage Category Discounts** in its
 * store: a scheduled one only - one that ran is ended, so its history stays; its sizes' candidates
 * written again, audited.
 */
final readonly class RemoveCategoryDiscountHandler
{
    public const string PERMISSION = PricingPermissions::CATEGORY_DISCOUNT_MANAGE;

    public function __construct(
        private PricingChange $change,
        private DiscountCoverage $coverage,
        private CategoryDiscounts $discounts,
        private CandidateWriter $writer,
    ) {}

    public function handle(RemoveCategoryDiscount $command): void
    {
        $found = $this->discounts->find($command->discountId) ?? throw new CategoryDiscountNotFound;
        $store = $this->change->authorize(self::PERMISSION, $found->storeId);
        $storeId = $store->storeId();

        $this->change->run($storeId, function () use ($command, $storeId, $store): array {
            $now = CarbonImmutable::now()->toDateTimeImmutable();
            $before = $this->discounts->find($command->discountId) ?? throw new CategoryDiscountNotFound;

            if (! $before->scheduled($now)) {
                throw new NotChangeable;
            }

            $sizes = array_keys($this->coverage->pricedSizes($storeId, [$before]));
            $this->discounts->remove($before->id);
            $this->writer->refresh($storeId, $sizes);

            return [null, [DiscountAudit::entry('pricing.category_discount.removed', $before, null, $store->currencyExponent)]];
        });
    }
}
