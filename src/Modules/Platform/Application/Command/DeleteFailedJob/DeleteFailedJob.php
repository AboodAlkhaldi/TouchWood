<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\DeleteFailedJob;

final readonly class DeleteFailedJob
{
    public function __construct(
        public string $id,
    ) {}
}
