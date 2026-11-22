<?php

declare(strict_types=1);

namespace Modules\Access\Domain\ValueObject;

/**
 * Access spec §1.5.
 */
enum RoleKind: string
{
    /** Shared: editing it changes it for everyone who holds it. */
    case Saved = 'SAVED';

    /** One staff member's own copy, made by editing their role from their page. */
    case Personal = 'PERSONAL';
}
