<?php

declare(strict_types=1);

namespace Modules\Access\Public\Dto;

/**
 * A staff member as another module may show them to the person reading (access.md §1.6, amendment
 * 54): "invited by", a decision's "decided by", an audit entry's actor.
 *
 * **A Super Admin, read by anyone but another Super Admin, is "System administrator"**: `name` is that,
 * in the reader's language, `id` is null and `systemAdministrator` is true — a screen then shows no
 * name, no id and no link. The record itself keeps the real id.
 */
final readonly class StaffDisplayNameDto
{
    public function __construct(
        /** Null when the person is shown as "System administrator". */
        public ?string $id,
        public string $name,
        public bool $systemAdministrator,
    ) {}
}
