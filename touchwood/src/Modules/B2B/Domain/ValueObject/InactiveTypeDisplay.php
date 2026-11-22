<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

/**
 * How a deactivated company type or document type looks to a new application (b2b.md §1.3,
 * amendment 5): left out of the form, or shown greyed out. Staff choose when they deactivate it;
 * an active type has no such choice, and activating it clears the one it had.
 */
enum InactiveTypeDisplay: string
{
    case Hidden = 'HIDDEN';
    case Greyed = 'GREYED';
}
