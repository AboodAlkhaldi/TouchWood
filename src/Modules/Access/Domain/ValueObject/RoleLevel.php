<?php

declare(strict_types=1);

namespace Modules\Access\Domain\ValueObject;

/**
 * Super Admin → admins → staff (owner's decision, 2026-09-19). Whoever holds an admin role is an
 * admin. Only a Super Admin creates, edits or assigns admin roles, and only admin roles may hold
 * the management actions.
 */
enum RoleLevel: string
{
    case Admin = 'ADMIN';

    case Staff = 'STAFF';
}
