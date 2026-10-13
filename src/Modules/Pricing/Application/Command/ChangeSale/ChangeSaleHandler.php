<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\ChangeSale;

use Carbon\CarbonImmutable;
use Modules\Pricing\Application\Prices\AmountInput;
use Modules\Pricing\Application\Prices\CandidateWriter;
use Modules\Pricing\Application\Prices\PricingChange;
use Modules\Pricing\Application\Prices\SaleAudit;
use Modules\Pricing\Application\Prices\SaleCheck;
use Modules\Pricing\Application\Prices\SaleInput;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\NotChangeable;
use Modules\Pricing\Domain\Exception\SaleNotFound;
use Modules\Pricing\Domain\Exception\WindowInvalid;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\ValueObject\Sale;

/**
 * **Changing a sale** (pricing.md §3, §4.1), under **Edit Prices and Sales** in its store: a scheduled
 * one in full, checked again as when added; a running one only its end, never before now; an ended one
 * never. Read again under the store's lock, so its state is the one now.
 */
final readonly class ChangeSaleHandler
{
    public const string PERMISSION = PricingPermissions::PRICE_EDIT;

    public function __construct(
        private PricingChange $change,
        private SaleCheck $check,
        private Sales $sales,
        private CandidateWriter $writer,
    ) {}

    public function handle(ChangeSale $command): void
    {
        $found = $this->sales->find($command->saleId) ?? throw new SaleNotFound;
        $store = $this->change->authorize(self::PERMISSION, $found->storeId);
        $storeId = $store->storeId();

        if (($command->percent === null) === ($command->price === null)) {
            throw new InvalidPricingInput($command->percent === null ? 'price' : 'percent');
        }

        $percent = $command->percent === null ? null : SaleInput::percent($command->percent);
        $price = $command->price === null ? null : AmountInput::of($command->price, $store);
        $endsAt = SaleInput::end($command->endsAt);

        $this->change->run($storeId, function () use ($command, $storeId, $store, $percent, $price, $endsAt): array {
            $now = CarbonImmutable::now()->toDateTimeImmutable();
            $before = $this->sales->find($command->saleId) ?? throw new SaleNotFound;

            if ($before->ended($now)) {
                throw new NotChangeable;
            }

            if ($before->running($now)) {
                // Running: its end alone, and never before now (§4.1).
                $same = $percent === $before->percentBasisPoints
                    && ($price === null ? $before->price === null : ($before->price !== null && $price->equals($before->price)))
                    && $command->alwaysWins === $before->alwaysWins;

                if (! $same) {
                    throw new NotChangeable;
                }

                if ($endsAt !== null && $endsAt < $now) {
                    throw new WindowInvalid;
                }

                $after = new Sale($before->id, $before->storeId, $before->productId, $before->percentBasisPoints, $before->price, $before->startsAt, $endsAt, $before->alwaysWins, $before->variantIds);
                $this->check->checkNewEnd($storeId, $after);
            } else {
                $after = new Sale($before->id, $before->storeId, $before->productId, $percent, $price, SaleInput::start($command->startsAt, $now), $endsAt, $command->alwaysWins, $before->variantIds);
                $this->check->check($storeId, $after);
            }

            $this->sales->save($after);
            $this->writer->refresh($storeId, $after->variantIds);

            return [null, [SaleAudit::entry('pricing.sale.changed', $before, $after, $store->currencyExponent)]];
        });
    }
}
