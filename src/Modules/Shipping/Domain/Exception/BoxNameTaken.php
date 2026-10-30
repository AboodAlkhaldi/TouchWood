<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A name another box of the store has, ignoring case and outer spaces - named, as a carrier's is (§9.4 #4).
 */
final class BoxNameTaken extends ShippingError
{
    public function __construct(public readonly string $otherName)
    {
        parent::__construct("Another box of the store is named {$this->otherName}.");
    }

    public function type(): string
    {
        return 'shipping.box_name_taken';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['name' => $this->otherName];
    }
}
