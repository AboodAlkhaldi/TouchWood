<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A name another carrier of the store has, in that language, ignoring case and outer spaces (owner, 2026-10-29, step 3 Q4): names it.
 */
final class CarrierNameTaken extends ShippingError
{
    public function __construct(public readonly string $language, public readonly string $otherNameAr, public readonly string $otherNameEn)
    {
        parent::__construct("Another carrier of the store is named {$this->otherNameEn}.");
    }

    public function type(): string
    {
        return 'shipping.carrier_name_taken';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['language' => $this->language, 'name_ar' => $this->otherNameAr, 'name_en' => $this->otherNameEn];
    }
}
