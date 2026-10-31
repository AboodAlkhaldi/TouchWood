<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Security;

use Illuminate\Database\ConnectionInterface;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\Exception\SignInMethodRequired;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\SettingGuard;
use Shared\Domain\ValueObject\StoreId;

/**
 * At least one staff sign-in method stays ticked (amendment 91, decision 1). Platform locks only the
 * box being changed, so both boxes take one lock of their own first: two Super Admins unticking one
 * box each at the same moment are taken one after the other, and the second reads the first's change
 * (a read inside a transaction is never the request's kept copy - access.md amendment 65).
 */
final readonly class SignInMethodsGuard implements SettingGuard
{
    public function __construct(
        private ConnectionInterface $db,
        private PlatformApi $platform,
    ) {}

    public function check(string $key, ?StoreId $store, mixed $from, mixed $to): void
    {
        if ($to !== false) {
            return;
        }

        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['access.staff.sign_in_methods'], useReadPdo: false);

        $other = $key === StaffSecuritySettings::SIGN_IN_BY_SMS ? StaffSecuritySettings::SIGN_IN_BY_AUTHENTICATOR : StaffSecuritySettings::SIGN_IN_BY_SMS;

        if (! $this->platform->setting($other)->bool()) {
            throw new SignInMethodRequired;
        }
    }
}
