<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A box that is not there, or is another store's (§7).
 */
final class BoxNotFound extends ShippingError
{
    public function __construct(public readonly string $boxId)
    {
        parent::__construct("No such box: {$this->boxId}.");
    }

    public function type(): string
    {
        return 'shipping.box_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
