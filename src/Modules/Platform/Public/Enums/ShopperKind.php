<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Enums;

/**
 * Who is looking at the shop (catalog.md §1.13, amendment 14(a)): what the shop shows depends on it.
 *
 * - **A guest** — nobody signed in, and a staff member viewing the shop from the panel (access.md
 *   §1.11) — sees what either kind of account sees (catalog.md P25).
 * - **An individual** sees the categories switched on for individuals, and buys retail only.
 * - **A company** — pending, rejected and suspended ones too (handoff §6) — sees the categories
 *   switched on for companies, retail and wholesale.
 */
enum ShopperKind: string
{
    case Guest = 'GUEST';
    case Individual = 'INDIVIDUAL';
    case Company = 'COMPANY';
}
