<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\ChangeCategoryDiscount;

use Carbon\CarbonImmutable;
use Modules\Pricing\Application\Prices\AmountInput;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\DiscountAudit;
use Modules\Pricing\Application\Prices\DiscountCheck;
use Modules\Pricing\Application\Prices\DiscountCoverage;
use Modules\Pricing\Application\Prices\DiscountInput;
use Modules\Pricing\Application\Prices\PriceWindowTasks;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\Prices\SaleInput;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\CategoryDiscountNotFound;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\NotChangeable;
use Modules\Pricing\Domain\Exception\WindowInvalid;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;

/**
 * **Changing a category discount** (pricing.md §3, §4.1), under **Manage Category Discounts** in its
 * store: a scheduled one in full, checked again as when added; a running one only its end, never before
 * now; an ended one never. Read again under the store's lock; the sizes it reached before and those it
 * reaches now have their candidates written again.
 */
final readonly class ChangeCategoryDiscountHandler
{
    public const string PERMISSION = PricingPermissions::CATEGORY_DISCOUNT_MANAGE;

    public function __construct(
        private PricingChange $change,
        private DiscountInput $input,
        private DiscountCheck $check,
        private DiscountCoverage $coverage,
        private CategoryDiscounts $discounts,
        private CandidateWriter $writer,
        private PriceWindowTasks $tasks,
    ) {}

    public function handle(ChangeCategoryDiscount $command): void
    {
        $found = $this->discounts->find($command->discountId) ?? throw new CategoryDiscountNotFound;
        $store = $this->change->authorize(self::PERMISSION, $found->storeId);
        $storeId = $store->storeId();

        if (($command->percent === null) === ($command->amount === null)) {
            throw new InvalidPricingInput($command->percent === null ? 'amount' : 'percent');
        }

        $percent = $command->percent === null ? null : SaleInput::percent($command->percent);
        $amount = $command->amount === null ? null : AmountInput::of($command->amount, $store);
        $lines = DiscountInput::lines($command->lines);
        $leftOut = $this->input->leftOut($command->leftOut);
        $categoryId = strtolower(trim($command->categoryId));
        $endsAt = SaleInput::end($command->endsAt);

        $this->change->run($storeId, function () use ($command, $storeId, $store, $percent, $amount, $lines, $leftOut, $categoryId, $endsAt): array {
            $now = CarbonImmutable::now()->toDateTimeImmutable();
            $before = $this->discounts->find($command->discountId) ?? throw new CategoryDiscountNotFound;

            if ($before->ended($now)) {
                throw new NotChangeable;
            }

            if ($before->running($now)) {
                // Running: its end alone, and never before now (§4.1).
                $same = $categoryId === $before->categoryId
                    && $percent === $before->percentBasisPoints
                    && ($amount === null ? $before->amount === null : ($before->amount !== null && $amount->equals($before->amount)))
                    && $lines === $before->lines
                    && $leftOut === $before->excludedProductIds
                    && $command->alwaysWins === $before->alwaysWins;

                if (! $same) {
                    throw new NotChangeable;
                }

                if ($endsAt !== null && $endsAt < $now) {
                    throw new WindowInvalid;
                }

                $after = new CategoryDiscount($before->id, $before->storeId, $before->categoryId, $before->percentBasisPoints, $before->amount, $before->lines, $before->startsAt, $endsAt, $before->alwaysWins, $before->excludedProductIds);
                $this->check->checkNewEnd($storeId, $after);
            } else {
                $after = new CategoryDiscount($before->id, $before->storeId, $categoryId, $percent, $amount, $lines, SaleInput::start($command->startsAt, $now), $endsAt, $command->alwaysWins, $leftOut);
                $this->check->check($storeId, $after);
            }

            // The sizes it reached before, and those it reaches now.
            $sizes = array_keys($this->coverage->pricedSizes($storeId, [$before, $after]));
            $this->discounts->save($after);
            $this->writer->refresh($storeId, $sizes, discountsChanged: true);
            $this->tasks->queue($storeId, $after->id, $after->momentsAfter($now));

            return [null, [DiscountAudit::entry('pricing.category_discount.changed', $before, $after, $store->currencyExponent)]];
        });
    }
}
