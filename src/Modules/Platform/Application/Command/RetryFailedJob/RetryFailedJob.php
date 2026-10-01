<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RetryFailedJob;

final readonly class RetryFailedJob
{
    public function __construct(
        public string $id,
    ) {}
}
