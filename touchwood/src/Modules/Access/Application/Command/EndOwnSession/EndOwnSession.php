<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\EndOwnSession;

/**
 * One session of the staff member's own, ended from their sessions screen (owner, 2026-09-26).
 *
 * The id names a row of theirs; a row belonging to anybody else is not found, not refused, because
 * this screen has no business saying whether somebody else's session exists.
 */
final readonly class EndOwnSession
{
    public function __construct(
        public string $sessionId,
    ) {}
}
