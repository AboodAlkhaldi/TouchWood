<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Repository;

use Modules\B2B\Domain\ValueObject\ApplicationReference;

/**
 * Where application numbers come from (b2b.md §1.2, §5, amendment 14(g)): one count per year,
 * across every store.
 */
interface ApplicationReferenceCounter
{
    /**
     * The next number of that year. **Call it inside the send's transaction**: the year's count is
     * held until the transaction ends, so two sends at once never share a number, and a send that
     * fails gives its number back — so the numbers of a year have no gaps.
     *
     * @param  int  $year  all four digits, in the home store's time zone
     *
     * @throws \LogicException outside a transaction
     */
    public function next(int $year): ApplicationReference;
}
