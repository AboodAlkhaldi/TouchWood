<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Command\CheckAttempt;

final readonly class CheckAttempt
{
    public function __construct(
        public string $attemptId,
    ) {}
}
