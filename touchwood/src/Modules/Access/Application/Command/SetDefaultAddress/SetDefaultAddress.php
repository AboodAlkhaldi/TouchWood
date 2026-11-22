<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SetDefaultAddress;

/**
 * The address checkout should offer first in its store (spec §1.9).
 */
final readonly class SetDefaultAddress
{
    public function __construct(
        public string $addressId,
    ) {}
}
