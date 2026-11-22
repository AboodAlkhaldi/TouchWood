<?php

declare(strict_types=1);

namespace Modules\Access\Public\Enums;

/**
 * What kind of customer an account is (Access spec §1.1, handoff §7.2). Chosen at registration and
 * **immutable**: a company's application belongs to B2B, which reads this.
 */
enum AccountType: string
{
    case Individual = 'INDIVIDUAL';

    /** The account of a company's responsible person; B2B owns the company application (§1.1). */
    case Company = 'COMPANY';
}
