<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\CurrentStore;

/**
 * The store the admin panel opens in, and whether it had to fall back (stage 2b, P3).
 */
final readonly class CurrentStoreDto
{
    /**
     * @param  string|null  $storeId  null only when the person has no stores at all
     * @param  bool  $fellBack  true when they had chosen a store and it is no longer theirs, so the
     *                          screen says which store it is showing instead
     */
    public function __construct(
        public ?string $storeId,
        public bool $fellBack,
    ) {}
}
