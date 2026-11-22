<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DeleteLabel;

final readonly class DeleteLabel
{
    public function __construct(
        public string $labelId,
    ) {}
}
