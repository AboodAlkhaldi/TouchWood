<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\EndSale;

use Carbon\CarbonImmutable;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\Prices\SaleAudit;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\NotChangeable;
use Modules\Pricing\Domain\Exception\SaleNotFound;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\ValueObject\Sale;

/**
 * **Ending a sale now** (pricing.md §3, §4.1), under **Edit Prices and Sales** in its store: a running
 * one only - a scheduled one is removed, an ended one is history.
 */
final readonly class EndSaleHandler
{
    public const string PERMISSION = PricingPermissions::PRICE_EDIT;

    public function __construct(
        private PricingChange $change,
        private Sales $sales,
        private CandidateWriter $writer,
    ) {}

    public function handle(EndSale $command): void
    {
        $found = $this->sales->find($command->saleId) ?? throw new SaleNotFound;
        $store = $this->change->authorize(self::PERMISSION, $found->storeId);
        $storeId = $store->storeId();

        $this->change->run($storeId, function () use ($command, $storeId, $store): array {
            $now = CarbonImmutable::now()->toDateTimeImmutable();
            $before = $this->sales->find($command->saleId) ?? throw new SaleNotFound;

            if (! $before->running($now)) {
                throw new NotChangeable;
            }

            $after = new Sale($before->id, $before->storeId, $before->productId, $before->percentBasisPoints, $before->price, $before->startsAt, $now, $before->alwaysWins, $before->variantIds);
            $this->sales->save($after);
            $this->writer->refresh($storeId, $after->variantIds);

            return [null, [SaleAudit::entry('pricing.sale.ended', $before, $after, $store->currencyExponent)]];
        });
    }
}
