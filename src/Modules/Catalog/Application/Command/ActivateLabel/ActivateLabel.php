<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\ActivateLabel;

final readonly class ActivateLabel
{
    public function __construct(
        public string $labelId,
    ) {}
}
