<?php

declare(strict_types=1);

namespace Shared\Application;

use Shared\Domain\ValueObject\StoreId;

/**
 * Checks that the current actor holds a permission. Implemented by Access.
 *
 * Every command handler calls this before doing anything else.
 */
interface Authorizer
{
    /**
     * @param  string  $permission  "{module}.{resource}.{action}", e.g. "platform.store.update"
     * @param  PermissionScope  $scope  what the check applies to: one store, every store, or
     *                                  nothing store-related. See PermissionScope.
     *
     * @throws Unauthorized
     */
    public function authorize(string $permission, PermissionScope $scope): void;

    /**
     * The stores the current actor may use this permission in, for admin listings such as
     * "orders in my stores" — asking for each store in turn would not scale.
     *
     * @return list<StoreId>|null null when the actor holds it in every store, now and for any
     *                            store added later; an empty list when the actor holds it nowhere
     */
    public function storesWith(string $permission): ?array;

    /**
     * Whether the current actor is not limited by permissions at all: a Super Admin, or the system
     * running a console command or a queued job on nobody's behalf.
     *
     * It answers one question no permission can: what to offer for a screen whose permission does
     * not exist yet — a module still to be built (stage 2b, P6, the admin menu's "coming soon"
     * entries). Never use it to decide whether an action is allowed: that is what authorize() is
     * for, and every handler calls it.
     */
    public function isUnlimited(): bool;
}
