<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\DeleteBox;

/**
 * A box taken off the list. Nothing names a box - a suggestion is worked out when it is shown - so it
 * may go whether it is on or off.
 */
final readonly class DeleteBox
{
    public function __construct(
        public string $storeId,
        public string $boxId,
    ) {}
}
