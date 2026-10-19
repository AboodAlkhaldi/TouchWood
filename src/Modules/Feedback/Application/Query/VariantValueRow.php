<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

/** One of the bought variant's values, in the reader's language — "Width: 60 cm" (feedback.md §1.1). */
final readonly class VariantValueRow
{
    public function __construct(
        public string $attribute,
        public string $value,
    ) {}
}
