<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\TransferCompanyType;

/**
 * Staff moving every company of one active type to another active type of the same store, both
 * staying offered (b2b.md §1.3, §3.2, amendment 11(c)).
 */
final readonly class TransferCompanyType
{
    public function __construct(
        public string $fromTypeId,
        public string $toTypeId,
    ) {}
}
