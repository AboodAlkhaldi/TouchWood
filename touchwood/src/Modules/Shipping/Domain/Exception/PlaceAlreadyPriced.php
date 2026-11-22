<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A place already in another group of this carrier (rule 3, §1.4): "This city is already at 20 in this
 * carrier". Named in both languages with that group's amount; "Move here" takes it out of there.
 */
final class PlaceAlreadyPriced extends ShippingError
{
    public function __construct(
        public readonly string $placeId,
        public readonly string $nameAr,
        public readonly string $nameEn,
        public readonly string $groupId,
        public readonly string $amount,
    ) {
        parent::__construct("{$this->nameEn} is already at {$this->amount} in this carrier.");
    }

    public function type(): string
    {
        return 'shipping.place_already_priced';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['place' => $this->placeId, 'name_ar' => $this->nameAr, 'name_en' => $this->nameEn, 'group' => $this->groupId, 'amount' => $this->amount];
    }
}
