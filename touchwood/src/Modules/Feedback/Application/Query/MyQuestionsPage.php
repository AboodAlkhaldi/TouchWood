<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

/** A page of my questions: `next` is what to ask for the following page, null on the last. */
final readonly class MyQuestionsPage
{
    /**
     * @param  list<MyQuestionRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
    ) {}
}
