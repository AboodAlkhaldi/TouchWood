<?php

declare(strict_types=1);

namespace Modules\Access\Application\Staff;

use Illuminate\Contracts\Translation\Translator;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Public\Dto\StaffDisplayNameDto;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;

/**
 * **The one place a staff member is named to somebody else** (access.md §1.6, amendment 54): the
 * audit log's actor, "invited by", another module's "decided by".
 *
 * A Super Admin is invisible to admins and staff — not listed, not counted, not named. So to anyone
 * but another Super Admin, a Super Admin reads as **"System administrator"**, in the reader's language,
 * with no name and no id; the record keeps the real id. Whoever is reading is the person acting: a
 * Super Admin, or the console with nobody behind it, sees the real names; a job sees what the person
 * who queued it would.
 */
final readonly class StaffDisplayNames
{
    public const string SYSTEM_ADMINISTRATOR = 'access::staff.system_administrator';

    public function __construct(
        private ActorContext $actors,
        private GrantsReader $grants,
        private StaffUserRepository $staff,
        private Translator $translator,
    ) {}

    /**
     * @param  list<string>  $ids  any ids; those that are not a staff member's are left out
     * @return array<string, StaffDisplayNameDto> keyed by id, lower-cased
     */
    public function forReader(array $ids): array
    {
        $ids = array_values(array_unique(array_map(strtolower(...), $ids)));
        $found = $ids === [] ? [] : $this->staff->displayNames($ids);

        if ($found === []) {
            return [];
        }

        $seesSuperAdmins = $this->readerSeesSuperAdmins();
        $names = [];

        foreach ($found as $id => $person) {
            $names[$id] = $person['superAdmin'] && ! $seesSuperAdmins
                ? new StaffDisplayNameDto(null, $this->systemAdministrator(), true)
                : new StaffDisplayNameDto($id, $person['name'], false);
        }

        return $names;
    }

    /**
     * A Super Admin sees Super Admins; so does the console, acting for nobody. Anyone else — an admin,
     * staff, a customer, a guest, a job queued by any of them — does not.
     */
    public function readerSeesSuperAdmins(): bool
    {
        $actor = $this->actors->current();

        if ($actor->type === ActorType::System) {
            if ($actor->requestedBy === null) {
                return true;
            }

            $actor = $actor->requestedBy;
        }

        if ($actor->type !== ActorType::Staff || $actor->id === null) {
            return false;
        }

        return $this->grants->forStaff($actor->id)?->superAdmin === true;
    }

    private function systemAdministrator(): string
    {
        $name = $this->translator->get(self::SYSTEM_ADMINISTRATOR);

        return is_string($name) ? $name : 'System administrator';
    }
}
