<?php

declare(strict_types=1);

namespace Modules\Access\Application\StaffView;

/**
 * A staff view that holds (spec §1.11): who is looking - by id, and by the name the shop's person
 * menu shows them - and the store it opened in.
 */
final readonly class StaffViewPass
{
    public function __construct(
        public string $staffId,
        public string $name,
        public string $storeId,
    ) {}
}
