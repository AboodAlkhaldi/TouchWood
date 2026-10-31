<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Security;

use Illuminate\Database\ConnectionInterface;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\Exception\SignInMethodRequired;
use Modules\Platform\Public\Contracts\SettingGuard;
use Shared\Domain\ValueObject\StoreId;

/**
 * At least one staff sign-in method stays ticked (amendment 91, decision 1). Platform locks only the
 * box being changed, so both boxes take one lock of their own first: two Super Admins unticking one
 * box each at the same moment are taken one after the other, and the second reads the first's change.
 * The other box is read from `platform.settings` itself, never from the settings cache (review of
 * amendment 91): only the row, read after the lock, is sure to hold what was committed. No row means the
 * box still has its default (StaffSecuritySettings).
 */
final readonly class SignInMethodsGuard implements SettingGuard
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function check(string $key, ?StoreId $store, mixed $from, mixed $to): void
    {
        if ($to !== false) {
            return;
        }

        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['access.staff.sign_in_methods'], useReadPdo: false);

        $other = $key === StaffSecuritySettings::SIGN_IN_BY_SMS ? StaffSecuritySettings::SIGN_IN_BY_AUTHENTICATOR : StaffSecuritySettings::SIGN_IN_BY_SMS;

        $stored = $this->db->table('platform.settings')->where('key', $other)->whereNull('store_id')->value('value');
        $on = $stored === null ? $this->default($other) : json_decode((string) $stored, true) === true;

        if (! $on) {
            throw new SignInMethodRequired;
        }
    }

    private function default(string $key): bool
    {
        foreach (StaffSecuritySettings::definitions() as $definition) {
            if ($definition->key === $key) {
                return $definition->default === true;
            }
        }

        return false;
    }
}
