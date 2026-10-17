<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Enums;

/**
 * Who is buying (promotions.md §2.2; handoff §6): anyone, or a company account approved in the store —
 * the only company that can order there (§1.1). Sales passes it in — it reads B2B; Promotions never
 * does. Promotions' own enum, as Loyalty's (loyalty.md §9.2: one Shared enum is the owner's call if a
 * third module needs it).
 */
enum Audience: string
{
    case Public = 'PUBLIC';
    case Company = 'COMPANY';
}
