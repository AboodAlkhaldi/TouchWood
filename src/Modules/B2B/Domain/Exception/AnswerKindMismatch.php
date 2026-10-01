<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An answer of the other kind than its request asks for (b2b.md §3.1, §7, amendment 5): text to a
 * request for a file, or a file to a request for text.
 */
final class AnswerKindMismatch extends B2BError
{
    public function __construct(public readonly string $asked = '', public readonly string $given = '')
    {
        parent::__construct("The request asks for {$asked}, and the answer is {$given}.");
    }

    public function type(): string
    {
        return 'b2b.answer_kind_mismatch';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
