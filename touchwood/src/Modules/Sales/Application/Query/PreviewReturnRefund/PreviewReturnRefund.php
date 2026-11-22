<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\PreviewReturnRefund;

final readonly class PreviewReturnRefund
{
    public function __construct(
        public string $returnId,
    ) {}
}
