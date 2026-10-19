<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\CorrectAnswer;

final readonly class CorrectAnswer
{
    public function __construct(
        public string $questionId,
        public string $answer,
    ) {}
}
