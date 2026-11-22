<?php

declare(strict_types=1);

namespace Modules\Access\Public\Enums;

/**
 * Whether a permission is held per store or not tied to any store (Access spec §1.5, owner's
 * decision 2026-09-19).
 *
 * A handler checks a store-free permission with PermissionScope::global() only, and a per-store
 * one with store() or allStores() only; the authorizer refuses any other combination.
 */
enum PermissionKind: string
{
    /** Held in chosen stores: the store row of the staff member, or an exception's stores. */
    case PerStore = 'PER_STORE';

    /**
     * Store-free: it concerns nothing that belongs to one store (media, roles), so holding it is
     * enough. The role editor shows its store boxes ticked and disabled.
     */
    case Global = 'GLOBAL';
}
