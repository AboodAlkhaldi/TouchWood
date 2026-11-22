<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Modules\Platform\Public\Dto\StaffNameDto;

/**
 * How a staff member is named **to the person reading** a screen (access.md §1.6, amendment 54).
 *
 * Platform records staff by id — in the audit log above all — and knows no names: Access owns people,
 * and Access binds this. It is also where the one rule about naming lives: **a Super Admin reads, to
 * anyone but another Super Admin, as "System administrator"**, with no name and no id. Until a module
 * binds it, nobody is named (Platform's own `UnnamedStaff`).
 */
interface StaffNames
{
    /**
     * @param  list<string>  $ids  any ids: an audit entry's actor, requester or subject — those that are
     *                             not a staff member's are simply left out of the answer
     * @return array<string, StaffNameDto> keyed by the id as given, lower-cased
     */
    public function forReader(array $ids): array;
}
