<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Enums;

/**
 * How the business carries a brand (handoff §9.4).
 */
enum AgencyType: string
{
    /** Its own brand. */
    case House = 'HOUSE';

    case ExclusiveAgent = 'EXCLUSIVE_AGENT';

    case Distributor = 'DISTRIBUTOR';
}
