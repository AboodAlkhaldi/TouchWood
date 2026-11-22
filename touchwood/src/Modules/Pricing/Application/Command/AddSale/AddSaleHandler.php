<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\AddSale;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Pricing\Application\Prices\AmountInput;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\PriceWindowTasks;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\Prices\SaleAudit;
use Modules\Pricing\Application\Prices\SaleCheck;
use Modules\Pricing\Application\Prices\SaleInput;
use Modules\Pricing\Application\Prices\SaleSizes;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\ValueObject\Sale;

/**
 * **Adding a sale** (pricing.md §1.3, §3), under **Edit Prices and Sales** in that store - in a wired
 * store too: every sale is ours, the provider gives the base price only (owner, 2026-10-13). For one
 * size, some of a product's (owner, 2026-10-13), or every size of a product (`SaleSizes`) - kept as one
 * sale over them, a size added later not in it. Checked by `SaleCheck` under the store's lock, saved,
 * its sizes' candidates written again and Catalog told, its start and end queued as timed tasks
 * (§1.10), audited.
 */
final readonly class AddSaleHandler
{
    public const string PERMISSION = PricingPermissions::PRICE_EDIT;

    public function __construct(
        private PricingChange $change,
        private SaleSizes $sizes,
        private SaleCheck $check,
        private Sales $sales,
        private CandidateWriter $writer,
        private PriceWindowTasks $tasks,
    ) {}

    /**
     * @return string the sale's id
     */
    public function handle(AddSale $command): string
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);
        $storeId = $store->storeId();

        if (($command->percent === null) === ($command->price === null)) {
            throw new InvalidPricingInput($command->percent === null ? 'price' : 'percent');
        }

        [$productId, $variantIds] = $this->sizes->of($storeId, $command->productId, $command->variantIds);
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $sale = new Sale(
            strtolower((string) Str::ulid()),
            $storeId->value,
            $productId,
            $command->percent === null ? null : SaleInput::percent($command->percent),
            $command->price === null ? null : AmountInput::of($command->price, $store),
            SaleInput::start($command->startsAt, $now),
            SaleInput::end($command->endsAt),
            $command->alwaysWins,
            $variantIds,
        );

        return $this->change->run($storeId, function () use ($storeId, $store, $sale, $now): array {
            $this->check->check($storeId, $sale);
            $this->sales->save($sale);
            $this->writer->refresh($storeId, $sale->variantIds);
            // Moments after the same "now" the sale was made with: one falling while the sizes are
            // written is pushed twice rather than never.
            $this->tasks->queue($storeId, $sale->id, $sale->momentsAfter($now));

            return [$sale->id, [SaleAudit::entry('pricing.sale.added', null, $sale, $store->currencyExponent)]];
        });
    }
}
