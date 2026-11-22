<?php

declare(strict_types=1);

namespace Modules\Access\Public\Contracts;

/**
 * What a module implements to draw a section of the staff customer page, G2 (access.md amendment
 * 72(a)): Loyalty's points in each store, say.
 *
 * It reads its data for one customer and the staff member looking - who it is, the module's own
 * handlers already know - in a fixed few queries, and answers null when this reader is to see no
 * section at all. Access never knows what is in it: the answer goes to the page as it is, and the
 * module's own component draws it.
 */
interface CustomerPageSection
{
    /**
     * @return array<string, mixed>|null the section's data, as the page will receive it
     */
    public function sectionFor(string $customerId): ?array;
}
