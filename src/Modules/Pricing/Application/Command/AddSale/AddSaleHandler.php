<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\AddSale;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Pricing\Application\Prices\AmountInput;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\Prices\SaleAudit;
use Modules\Pricing\Application\Prices\SaleCheck;
use Modules\Pricing\Application\Prices\SaleInput;
use Modules\Pricing\Application\Prices\SizeInput;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\ValueObject\Sale;

/**
 * **Adding a sale** (pricing.md §1.3, §3), under **Edit Prices and Sales** in that store - in a wired
 * store too: every sale is ours, the provider gives the base price only (owner, 2026-10-13). For one
 * size, or every size of a product - kept as one sale over them, a size added later not in it. Checked by `SaleCheck` under the store's lock, saved, its sizes' candidates written
 * again and Catalog told, audited.
 */
final readonly class AddSaleHandler
{
    public const string PERMISSION = PricingPermissions::PRICE_EDIT;

    public function __construct(
        private PricingChange $change,
        private CatalogApi $catalog,
        private SizeInput $sizes,
        private SaleCheck $check,
        private Sales $sales,
        private CandidateWriter $writer,
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

        [$productId, $variantIds] = $this->sizesOf($command);
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

        return $this->change->run($storeId, function () use ($storeId, $store, $sale): array {
            $this->check->check($storeId, $sale);
            $this->sales->save($sale);
            $this->writer->refresh($storeId, $sale->variantIds);

            return [$sale->id, [SaleAudit::entry('pricing.sale.added', null, $sale, $store->currencyExponent)]];
        });
    }

    /**
     * @return array{string, list<string>} the product, and the sizes in size-id order
     */
    private function sizesOf(AddSale $command): array
    {
        if ($command->variantIds === []) {
            // Every size of the product, as it is now (§1.3); archived ones left out, and none of an
            // archived product - read-only until it is restored (§7; owner, 2026-10-11).
            $product = $command->productId === null ? null : $this->catalog->product($command->productId);
            $variantIds = $product === null || $product->stage === ProductStage::Archived ? [] : $this->catalog->variantIdsOf($product->id);

            if ($product === null || $variantIds === []) {
                throw new InvalidPricingInput('product');
            }
        } else {
            // One size (§1.3): never some of a product's, never two products'.
            $variantIds = array_values(array_unique($this->sizes->check($command->variantIds)));
            $productId = count($variantIds) === 1 ? $this->catalog->variant($variantIds[0])?->productId : null;

            if ($productId === null || ($command->productId !== null && strtolower(trim($command->productId)) !== $productId)) {
                throw new InvalidPricingInput('variant');
            }

            $product = $this->catalog->product($productId) ?? throw new InvalidPricingInput('product');
        }

        sort($variantIds);

        return [$product->id, $variantIds];
    }
}
