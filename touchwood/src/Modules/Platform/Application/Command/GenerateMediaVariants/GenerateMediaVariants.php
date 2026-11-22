<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\GenerateMediaVariants;

final readonly class GenerateMediaVariants
{
    public function __construct(
        public string $mediaId,
    ) {}
}
