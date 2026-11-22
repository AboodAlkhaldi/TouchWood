<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Enums;

/**
 * Who is buying (loyalty.md §2.2; handoff §6): an individual, or an approved company. Sales passes it
 * in — it reads B2B; Loyalty never does. Each audience reads its own redemption mode (§1.5).
 * Loyalty's own enum (§9.2(1), agreed with the stage 5 session on 2026-10-07).
 */
enum Audience: string
{
    case Public = 'PUBLIC';
    case Company = 'COMPANY';
}
