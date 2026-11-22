<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query\ViewAttempt;

final readonly class ViewAttempt
{
    public function __construct(
        public string $attemptId,
    ) {}
}
