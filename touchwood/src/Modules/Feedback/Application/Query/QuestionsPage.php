<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

/** A page of a store's question queue: `next` is what to ask for the following page, null on the last. */
final readonly class QuestionsPage
{
    /**
     * @param  list<QuestionRow>  $rows
     * @param  array<string, int>  $counts  the store's questions in each state, every state named, when asked; else none
     */
    public function __construct(
        public array $rows,
        public ?string $next,
        public array $counts = [],
    ) {}
}
