<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\SetCartCoupon;

/** The coupon code typed into a customer's cart, or none (sales.md §1.1). */
final readonly class SetCartCoupon
{
    public function __construct(
        public string $storeId,
        public ?string $code,
    ) {}
}
