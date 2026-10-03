<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeactivateLabel;

final readonly class DeactivateLabel
{
    public function __construct(
        public string $labelId,
    ) {}
}
