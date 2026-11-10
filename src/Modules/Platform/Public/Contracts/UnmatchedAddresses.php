<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

/**
 * Where a module tells Platform who may answer an address nobody answers (content.md §2.3 row L2): Content's
 * redirects from the old site. Register in your module's service provider. With none registered - or none
 * answering - the site says "not found" exactly as it does without this.
 */
interface UnmatchedAddresses
{
    /**
     * @param  string  $answer  the class name of an UnmatchedAddress, checked when registered; resolved when
     *                          asked. Asked in the order registered, the first answer wins
     *
     * @throws \LogicException for a class that is not an UnmatchedAddress, or one registered twice
     */
    public function register(string $answer): void;
}
