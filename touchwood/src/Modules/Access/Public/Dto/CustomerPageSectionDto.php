<?php

declare(strict_types=1);

namespace Modules\Access\Public\Dto;

use Modules\Access\Public\Contracts\CustomerPageSection;

/**
 * A section another module adds to the staff customer page, G2 (access.md amendment 72(a)).
 *
 * The page draws it with the module's own component, `resources/js/sections/customer/{module}-{key}.tsx`,
 * under the customer's addresses, lowest position first.
 */
final readonly class CustomerPageSectionDto
{
    /**
     * @param  string  $module  the module that owns the section, e.g. "loyalty"
     * @param  string  $key  unique within that module
     * @param  class-string  $section  the CustomerPageSection that reads its data (checked when
     *                                 registered)
     * @param  list<string>  $words  the word files its component says things from, e.g.
     *                               "loyalty::admin_points"
     * @param  int  $position  where it sits among the other modules' sections, lowest first
     */
    public function __construct(
        public string $module,
        public string $key,
        public string $section,
        public array $words = [],
        public int $position = 0,
    ) {}
}
