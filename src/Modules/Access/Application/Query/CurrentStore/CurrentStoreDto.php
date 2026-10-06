<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\CurrentStore;

/**
 * The store the admin panel opens in, and whether it had to fall back (stage 2b, P3).
 */
final readonly class CurrentStoreDto
{
    /**
     * @param  string|null  $storeId  null when there is no store they may work in: none at all, or
     *                                only stores that are switched off
     * @param  bool  $fellBack  true when they had chosen a store they may no longer work in, so the
     *                          screen says which store it is showing instead
     * @param  list<string>  $available  every store that is theirs, on or off, in the stores' own
     *                                   order - what the picker shows, and never one store more
     * @param  list<string>  $off  those of them that are switched off: shown marked Off, and chosen
     *                             only by someone who may work in an off store (access.md amendment
     *                             58(a))
     * @param  bool  $mayChooseOff  a Super Admin, who works in an off store to prepare it before it
     *                              opens (platform.md §1.6; owner, 2026-10-03)
     * @param  bool  $fellBackFromOff  the store they had chosen was switched off, rather than taken
     *                                 away from them, so the screen says that instead
     */
    public function __construct(
        public ?string $storeId,
        public bool $fellBack,
        public array $available = [],
        public array $off = [],
        public bool $mayChooseOff = false,
        public bool $fellBackFromOff = false,
    ) {}
}
