<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\WithdrawReturn;

final readonly class WithdrawReturn
{
    public function __construct(
        public string $returnId,
    ) {}
}
