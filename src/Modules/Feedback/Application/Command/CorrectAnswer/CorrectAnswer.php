<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\CorrectAnswer;

final readonly class CorrectAnswer
{
    public function __construct(
        public string $questionId,
        public string $answer,
        /** The question's language as staff set it, its answer written in it (§2.4 B3); null keeps it. */
        public ?string $locale = null,
    ) {}
}
