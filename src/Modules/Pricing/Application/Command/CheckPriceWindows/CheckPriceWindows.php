<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\CheckPriceWindows;

/**
 * The daily safety check (pricing.md §1.10): every size whose sale started or ended in the last day
 * pushed again, in case a timed task was lost.
 */
final readonly class CheckPriceWindows {}
