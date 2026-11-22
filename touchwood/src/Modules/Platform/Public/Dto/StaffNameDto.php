<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

/**
 * A staff member as the person reading may be shown them (access.md amendment 54).
 *
 * `hidden` is true for a Super Admin read by anyone but another Super Admin: `name` is then
 * "System administrator" in the reader's language, and the screen shows **no id, no link and no
 * address** for them — the entry itself stays.
 */
final readonly class StaffNameDto
{
    public function __construct(
        public string $name,
        public bool $hidden,
    ) {}
}
