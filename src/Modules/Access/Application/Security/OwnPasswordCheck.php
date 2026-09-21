<?php

declare(strict_types=1);

namespace Modules\Access\Application\Security;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Domain\Exception\AccountLocked;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Platform\Public\Contracts\PlatformApi;

/**
 * "Type your current password" — what a staff member proves before a change that could hand their
 * account to someone else: the password itself, and the phone the sign-in code goes to (owner,
 * 2026-09-21).
 *
 * A wrong password counts exactly like a wrong one at sign-in, on the staff keys: a stolen session
 * cannot guess it without limit, and the lockout is audited while the attempts before it are only
 * counted (review of step 3b, amendment 32).
 */
final readonly class OwnPasswordCheck
{
    public function __construct(
        private PasswordPolicy $passwords,
        private SignInLimits $limits,
        private Codes $codes,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /**
     * @throws InvalidAccessAttribute when it is not the current password
     * @throws AccountLocked when too many were tried
     */
    public function confirm(StaffUser $staff, string $password, string $ip): void
    {
        $email = $staff->email()->value;
        $this->limits->begin($email, $ip);

        if (! $this->passwords->matches($password, $staff->passwordHash())) {
            $locked = $this->limits->failed($email, $ip);

            if ($locked['account'] || $locked['address']) {
                $this->db->transaction(function () use ($locked, $staff, $ip): void {
                    if ($locked['account']) {
                        $this->platform->recordAudit(StaffAudit::event('access.staff_user.locked_out', $staff));
                    }

                    if ($locked['address']) {
                        $this->platform->recordAudit(StaffAudit::addressLocked($this->codes->hash('sign-in-address', $ip)));
                    }
                });
            }

            throw new InvalidAccessAttribute('current_password', 'not the current password');
        }

        $this->limits->succeeded($email, $ip);
    }
}
