<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\RefreshSegment;

final readonly class RefreshSegment
{
    public function __construct(
        public string $segmentId,
    ) {}
}
