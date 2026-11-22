<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\DeleteAddress;

/**
 * The customer removes one of their addresses (spec §1.9). An order that already used it keeps its
 * own copy, so nothing on a past order changes.
 */
final readonly class DeleteAddress
{
    public function __construct(
        public string $addressId,
    ) {}
}
