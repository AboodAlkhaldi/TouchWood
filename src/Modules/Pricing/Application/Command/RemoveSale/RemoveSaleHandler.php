<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\RemoveSale;

use Carbon\CarbonImmutable;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\Prices\SaleAudit;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\NotChangeable;
use Modules\Pricing\Domain\Exception\SaleNotFound;
use Modules\Pricing\Domain\Repository\Sales;

/**
 * **Removing a sale** (pricing.md §3, §4.1), under **Edit Prices and Sales** in its store: a scheduled
 * one only - a running one is ended, an ended one is history.
 */
final readonly class RemoveSaleHandler
{
    public const string PERMISSION = PricingPermissions::PRICE_EDIT;

    public function __construct(
        private PricingChange $change,
        private Sales $sales,
        private CandidateWriter $writer,
    ) {}

    public function handle(RemoveSale $command): void
    {
        $found = $this->sales->find($command->saleId) ?? throw new SaleNotFound;
        $store = $this->change->authorize(self::PERMISSION, $found->storeId);
        $storeId = $store->storeId();

        $this->change->run($storeId, function () use ($command, $storeId, $store): array {
            $before = $this->sales->find($command->saleId) ?? throw new SaleNotFound;

            if (! $before->scheduled(CarbonImmutable::now()->toDateTimeImmutable())) {
                throw new NotChangeable;
            }

            $this->sales->remove($before->id);
            $this->writer->refresh($storeId, $before->variantIds);

            return [null, [SaleAudit::entry('pricing.sale.removed', $before, null, $store->currencyExponent)]];
        });
    }
}
