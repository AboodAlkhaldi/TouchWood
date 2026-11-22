<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewMyCompany;

/**
 * An application's answer to one request of the rejection before it (b2b.md §1.2): a text, or a file
 * opened through OpenMyApplicationFile.
 */
final readonly class AnswerView
{
    public function __construct(
        public string $requestId,
        public ?string $text,
        public ?string $mediaId,
    ) {}
}
