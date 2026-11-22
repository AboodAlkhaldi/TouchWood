<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\OpenStaffView;

/**
 * Opens a store's shop as the staff member asking (spec §1.11): the store chosen in View Store's menu
 * (amendment 64). It is checked against the person's own stores, so a request cannot reach another.
 */
final readonly class OpenStaffView
{
    public function __construct(
        public string $storeCode,
    ) {}
}
