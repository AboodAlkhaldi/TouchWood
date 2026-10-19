<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Feedback's `feedback.questions` section of a product's page (feedback.md §1.3): ten answered questions,
 * newest answer first, `next` for "Show more" (asked as `after[feedback.questions]`), and `canAsk` —
 * signed in; the five-waiting limit is checked when it is sent.
 */
#[TypeScript]
final class ShopQuestionsData extends Data
{
    /**
     * @param  list<ShopQuestionData>  $questions
     */
    public function __construct(
        public array $questions,
        public ?string $next,
        public bool $canAsk,
    ) {}
}
