<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\ChangeCarrier;

/**
 * A carrier's details, the whole form (shipping.md §1.3). Its country is not among them: it is fixed
 * once the carrier exists.
 */
final readonly class ChangeCarrier
{
    public function __construct(
        public string $storeId,
        public string $carrierId,
        public string $nameAr,
        public string $nameEn,
        public ?string $logoMediaId = null,
        public ?string $phone = null,
        public ?string $website = null,
        public ?string $trackingPattern = null,
    ) {}
}
