<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ViewFailedJob;

final readonly class ViewFailedJob
{
    public function __construct(
        public string $id,
    ) {}
}
