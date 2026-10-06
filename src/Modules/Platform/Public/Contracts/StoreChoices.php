<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * The stores a screen's own store filter offers (platform.md §9.10; owner, 2026-10-06): the panel
 * has no store "worked in" any more, so each store screen chooses its own — Settings, the B2B type
 * lists, any module's screen to come.
 *
 * Offering is not allowing: the handler behind the screen still asserts its permission for the
 * store chosen.
 */
interface StoreChoices
{
    /**
     * The stores where the person acting now holds any of these permissions, by the stores' own
     * order. Whoever may switch stores (a Super Admin) gets every store, the ones that are off too -
     * to prepare them before they open (§1.6); anyone else the stores that are on.
     *
     * @return list<StoreDto>
     */
    public function forJobs(string ...$permissions): array;

    /**
     * The store a screen shows: the one asked for by its code when it is among `forJobs()`, the
     * first of them that is on when none is asked - nobody lands in an off store without choosing
     * it - and null when there is none.
     *
     * @throws Unauthorized when a store is asked for that is not among them
     */
    public function chosen(?string $code, string ...$permissions): ?StoreDto;

    /**
     * The time zone a panel screen writes its moments in when it shows no one store: the base
     * store's (frontend.md §1.10; owner, 2026-10-06).
     */
    public function baseTimezone(): string;
}
