<?php

namespace Modules\Platform\Application;

use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * Writes audit entries. Callers record inside the transaction of the change they audit, so a
 * change that rolls back leaves no entry, and a committed change always has one.
 */
interface AuditLog
{
    public function record(AuditEntryDto $entry): void;
}
