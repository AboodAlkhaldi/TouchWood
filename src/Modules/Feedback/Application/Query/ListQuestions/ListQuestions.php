<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query\ListQuestions;

/** A store's questions in one state, a page at a time (feedback.md §1.3, §3). */
final readonly class ListQuestions
{
    public function __construct(
        public string $storeId,
        public string $status,
        public string $locale,
        public ?string $after = null,
    ) {}
}
