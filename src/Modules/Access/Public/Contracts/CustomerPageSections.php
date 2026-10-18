<?php

declare(strict_types=1);

namespace Modules\Access\Public\Contracts;

use Modules\Access\Public\Dto\CustomerPageSectionDto;

/**
 * The sections other modules add to the staff customer page, G2 (access.md amendment 72(a)),
 * registered in their service providers as account pages are (amendment 50).
 *
 * Offering a section is not allowing anything: every button inside one goes to its own module's
 * handler, which asks again (handoff §19).
 */
interface CustomerPageSections
{
    /**
     * @throws \LogicException for two sections with the same module and key, or a class that is not
     *                         a CustomerPageSection
     */
    public function register(CustomerPageSectionDto ...$sections): void;

    /**
     * Every section, lowest position first.
     *
     * @return list<CustomerPageSectionDto>
     */
    public function all(): array;
}
