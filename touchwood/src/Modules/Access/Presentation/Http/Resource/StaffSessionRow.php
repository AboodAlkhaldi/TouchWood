<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One browser a staff member is signed in on (owner, 2026-09-26).
 *
 * The browser string is sent whole and named on the screen; nothing here guesses a device from it,
 * because a guess that says "Windows" to somebody holding a phone is worse than the string itself.
 */
#[TypeScript]
final class StaffSessionRow extends Data
{
    public function __construct(
        public string $id,
        public ?string $ipAddress,
        public ?string $userAgent,
        public string $lastActivity,
        /** The one reading the page. Ending it signs them out, and the screen says so. */
        public bool $isCurrent,
    ) {}
}
