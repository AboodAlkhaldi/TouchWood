<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\EnableStaff;

/**
 * A disabled staff member back at work. Someone who never accepted their invitation gets a new one
 * instead, because they have no password yet (spec §4.3).
 */
final readonly class EnableStaff
{
    public function __construct(
        public string $staffId,
    ) {}
}
