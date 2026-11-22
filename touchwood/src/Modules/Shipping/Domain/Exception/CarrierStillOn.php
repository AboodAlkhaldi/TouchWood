<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Deleting a carrier that is switched on: switch it off first (§1.3, owner 2026-10-28 "switch then remove").
 */
final class CarrierStillOn extends ShippingError
{
    public function __construct(public readonly string $nameAr, public readonly string $nameEn)
    {
        parent::__construct("The carrier {$this->nameEn} is still on.");
    }

    public function type(): string
    {
        return 'shipping.carrier_still_on';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['name_ar' => $this->nameAr, 'name_en' => $this->nameEn];
    }
}
