<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\AnswerQuestion;

final readonly class AnswerQuestion
{
    public function __construct(
        public string $questionId,
        public string $answer,
    ) {}
}
