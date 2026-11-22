<?php

declare(strict_types=1);

namespace Modules\Platform\Application;

use DateTimeImmutable;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Actor;

/**
 * Writes audit entries. Callers record inside the transaction of the change they audit, so a
 * change that rolls back leaves no entry, and a committed change always has one.
 *
 * The source (web, integration, console, job) and the date are worked out here, never passed in.
 */
interface AuditLog
{
    public function record(AuditEntryDto $entry): void;

    /**
     * History from the old system, with its real date and actor. Only the system may import,
     * and only a past date is accepted; the entry also keeps the moment it was written.
     */
    public function recordImported(AuditEntryDto $entry, Actor $actor, DateTimeImmutable $occurredAt): void;
}
