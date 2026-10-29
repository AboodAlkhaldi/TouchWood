<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query;

use Modules\Platform\Public\Dto\TranslatedTextDto;
use Modules\Shipping\Domain\Model\Carrier;

/**
 * One carrier, as the carriers screens show it (shipping.md §4.1): its details, on or off, its place
 * in its country's list. `freeAboveMinor` is its own free-shipping amount (step 4), in the store's
 * currency's smallest unit.
 */
final readonly class CarrierRow
{
    public function __construct(
        public string $id,
        public string $countryCode,
        public TranslatedTextDto $name,
        public ?string $logoMediaId,
        public ?string $phone,
        public ?string $website,
        public ?string $trackingPattern,
        public ?int $freeAboveMinor,
        public bool $active,
        public int $position,
    ) {}

    public static function of(Carrier $carrier): self
    {
        $details = $carrier->details();

        return new self(
            $carrier->id,
            $carrier->countryCode,
            new TranslatedTextDto($details->nameAr, $details->nameEn),
            $carrier->logoMediaId(),
            $details->phone,
            $details->website,
            $details->trackingPattern,
            $carrier->freeAboveMinor(),
            $carrier->isActive(),
            $carrier->position,
        );
    }
}
