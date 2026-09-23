<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One staff member in the list (frontend.md 3.3, C1).
 *
 * An admin seen by anyone who is not a Super Admin shows a name and a role and nothing else - no
 * address, no status, no date. A colleague's account is not theirs to follow (R1), and Access
 * answers that by leaving those fields empty rather than by trusting the screen to hide them.
 *
 * No picture here, and the list shows initials instead. The design asks for one, but Access's staff
 * read does not carry it and Platform can only resolve media one id at a time - so a list of two
 * hundred people would be two hundred lookups. Showing the picture needs a batch read on Platform's
 * contract, which is Platform's decision and its own step. Flagged for the owner, 2026-09-23.
 */
#[TypeScript]
final class StaffRow extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        /** Their role in the language the panel is being read in. */
        public string $roleName,
        public bool $isAdmin,
        public ?string $jobTitle,
        public ?string $email,
        /** ACTIVE, INVITED, DISABLED - or null for an admin this reader may not see in full. */
        public ?string $status,
        /** The day they joined, or were invited. Null for the same reason as the status. */
        public ?string $since,
    ) {}
}
