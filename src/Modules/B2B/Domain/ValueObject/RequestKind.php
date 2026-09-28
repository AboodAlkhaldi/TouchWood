<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

/**
 * What a request on a rejection asks for (b2b.md §1.2, amendment 4): a text answer, or a file.
 * An answer of the other kind is refused (`AnswerKindMismatch`).
 */
enum RequestKind: string
{
    case Text = 'TEXT';
    case File = 'FILE';
}
