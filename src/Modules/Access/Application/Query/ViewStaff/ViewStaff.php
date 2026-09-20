<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ViewStaff;

/**
 * One staff member, for a reader who may see them (spec §3.3). A Super Admin asked for by id is
 * answered as no staff member at all, unless the reader is one (amendment 43).
 */
final readonly class ViewStaff
{
    public function __construct(
        public string $staffId,
    ) {}
}
