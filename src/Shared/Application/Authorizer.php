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
     * @param  StoreId|null  $store  the store the action touches; null for global resources
     *
     * @throws Unauthorized
     */
    public function authorize(string $permission, ?StoreId $store = null): void;
}
