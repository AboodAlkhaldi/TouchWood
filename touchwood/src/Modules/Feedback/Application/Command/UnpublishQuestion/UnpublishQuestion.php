<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\UnpublishQuestion;

final readonly class UnpublishQuestion
{
    public function __construct(
        public string $questionId,
        public ?string $reason = null,
    ) {}
}
