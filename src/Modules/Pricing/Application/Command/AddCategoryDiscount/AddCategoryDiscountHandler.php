<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\AddCategoryDiscount;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
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
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;

/**
 * **Adding a category discount** (pricing.md §1.5, §3), under **Manage Category Discounts** in that
 * store - a wired store's too: every discount is ours (owner, 2026-10-13). Checked by `DiscountCheck`
 * under the store's lock, saved, the candidates of the sizes it reaches written again and Catalog told,
 * its start and end queued as timed tasks (§1.10), audited.
 */
final readonly class AddCategoryDiscountHandler
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

    /**
     * @return string the discount's id
     */
    public function handle(AddCategoryDiscount $command): string
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);
        $storeId = $store->storeId();

        if (($command->percent === null) === ($command->amount === null)) {
            throw new InvalidPricingInput($command->percent === null ? 'amount' : 'percent');
        }

        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $discount = new CategoryDiscount(
            strtolower((string) Str::ulid()),
            $storeId->value,
            strtolower(trim($command->categoryId)),
            $command->percent === null ? null : SaleInput::percent($command->percent),
            $command->amount === null ? null : AmountInput::of($command->amount, $store),
            DiscountInput::lines($command->lines),
            SaleInput::start($command->startsAt, $now),
            SaleInput::end($command->endsAt),
            $command->alwaysWins,
            $this->input->leftOut($command->leftOut),
        );

        return $this->change->run($storeId, function () use ($storeId, $store, $discount, $now): array {
            $this->check->check($storeId, $discount);
            $this->discounts->save($discount);
            $this->writer->refresh($storeId, array_keys($this->coverage->pricedSizes($storeId, [$discount])));
            $this->tasks->queue($storeId, $discount->id, $discount->momentsAfter($now));

            return [$discount->id, [DiscountAudit::entry('pricing.category_discount.added', null, $discount, $store->currencyExponent)]];
        });
    }
}
