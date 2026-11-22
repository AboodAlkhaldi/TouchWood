<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\ReleaseExpiredHolds;

/**
 * Free every hold whose expiry has passed (inventory.md §1.4, §3): the scheduled job, every minute.
 */
final readonly class ReleaseExpiredHolds {}
