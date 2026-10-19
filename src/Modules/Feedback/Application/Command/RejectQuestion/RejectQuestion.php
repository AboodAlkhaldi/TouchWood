<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\RejectQuestion;

final readonly class RejectQuestion
{
    public function __construct(
        public string $questionId,
        public ?string $reason = null,
    ) {}
}
