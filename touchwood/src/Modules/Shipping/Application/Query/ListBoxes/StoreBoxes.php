<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ListBoxes;

/**
 * A store's boxes in their order, and the padding kept on every side of a box's inside (mm).
 */
final readonly class StoreBoxes
{
    /**
     * @param  list<BoxRow>  $boxes
     */
    public function __construct(
        public int $paddingMm,
        public array $boxes,
    ) {}
}
