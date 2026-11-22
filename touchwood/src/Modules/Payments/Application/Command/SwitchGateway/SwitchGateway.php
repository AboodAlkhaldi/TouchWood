<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Command\SwitchGateway;

/**
 * A Super Admin switches the store's gateway on or off (payments.md §1.2). Off, individuals are offered
 * "staff will contact you" only; attempts already open still finish.
 */
final readonly class SwitchGateway
{
    public function __construct(
        public string $storeId,
        public bool $on,
    ) {}
}
