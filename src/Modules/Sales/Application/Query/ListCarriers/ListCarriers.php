<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ListCarriers;

/** The carriers staff may ship a store's order with (§3.2); the order's country, when given, first. */
final readonly class ListCarriers
{
    public function __construct(
        public string $storeId,
        public ?string $countryCode = null,
    ) {}
}
