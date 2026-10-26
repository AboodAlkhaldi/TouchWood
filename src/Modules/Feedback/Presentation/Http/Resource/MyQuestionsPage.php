<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** F8 - My Questions: the customer's questions in every store, newest first; `next` the next page's cursor. */
#[TypeScript]
final class MyQuestionsPage extends Data
{
    /**
     * @param  list<MyQuestionData>  $questions
     */
    public function __construct(
        public array $questions,
        public ?string $next,
    ) {}
}
