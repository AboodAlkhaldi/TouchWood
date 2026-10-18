<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\DeleteSegment;

final readonly class DeleteSegment
{
    public function __construct(
        public string $segmentId,
    ) {}
}
