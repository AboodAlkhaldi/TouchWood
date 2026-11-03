<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

/**
 * What a module implements to put a part in the panel's frame (ops.md §4.15 O1): Ops' bell in the top
 * bar of every panel page. The panel's twin of ShopFramePart; Platform carries it with the page and
 * never knows what it holds.
 *
 * Asked on every full panel page for a signed-in staff member, never on a partial reload, so what it
 * reads is counted in AdminFrameQueryBudgetTest.
 */
interface AdminFramePart
{
    /**
     * What the frame draws for whoever is signed in, in this language: plain data only.
     *
     * @return array<string, mixed>
     */
    public function partFor(string $locale): array;
}
