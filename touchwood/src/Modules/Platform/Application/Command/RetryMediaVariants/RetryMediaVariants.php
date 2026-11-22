<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RetryMediaVariants;

final readonly class RetryMediaVariants
{
    public function __construct(
        public string $mediaId,
    ) {}
}
