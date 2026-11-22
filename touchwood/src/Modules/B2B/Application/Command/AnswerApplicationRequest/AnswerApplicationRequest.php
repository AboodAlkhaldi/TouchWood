<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\AnswerApplicationRequest;

/**
 * Answers one request of the last rejection in the open draft (b2b.md §1.2, amendments 4 and 5):
 * with text, or with a file — exactly one of the two, as the request asks.
 */
final readonly class AnswerApplicationRequest
{
    /**
     * @param  string|null  $text  a text answer
     * @param  string|null  $path  a file answer: the uploaded file on local disk
     * @param  string|null  $originalFilename  and its name, as the person's browser gave it
     */
    public function __construct(
        public string $requestId,
        public ?string $text = null,
        public ?string $path = null,
        public ?string $originalFilename = null,
    ) {}
}
