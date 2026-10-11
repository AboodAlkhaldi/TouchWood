<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Dto;

/**
 * The permission that allowed a caller's change to points (loyalty.md §2.1) — Sales's placing an
 * order, its delivery, its cancellation, its return. **No scope**: Loyalty builds it from the store
 * the change touches, the order's recorded store, so a caller cannot change one store's points with a
 * permission held in another. The permission must belong to the calling module (Platform's
 * `ModuleDeleteDto` is the precedent).
 */
final readonly class CallerPermission
{
    public function __construct(
        /** The calling module, e.g. "sales". */
        public string $module,
        /** Its permission, e.g. "sales.order.place"; refused unless it starts with the module. */
        public string $permission,
    ) {}
}
