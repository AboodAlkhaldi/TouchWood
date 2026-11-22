<?php

declare(strict_types=1);

namespace Modules\Access\Application\Security;

/**
 * SMS codes. A code has only a million values, so it is stored as a keyed hash (HMAC with the
 * application key): a copy of the database alone cannot be searched for it. The same keyed hash
 * names an IP address in the audit log (StaffAudit::addressLocked), which has too few values too.
 */
interface Codes
{
    /**
     * @return string $length random digits
     */
    public function generate(int $length): string;

    /**
     * The same code for another staff member hashes differently.
     */
    public function hash(string $owner, string $code): string;

    public function matches(string $owner, string $code, string $hash): bool;
}
