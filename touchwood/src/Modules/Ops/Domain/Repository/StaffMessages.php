<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Repository;

use Modules\Ops\Domain\Model\StaffMessage;

/** What staff wrote to customers (ops.md §1.8, §5: `ops.staff_messages`). Written once; only the sender counts on it. */
interface StaffMessages
{
    public function add(StaffMessage $message): void;
}
