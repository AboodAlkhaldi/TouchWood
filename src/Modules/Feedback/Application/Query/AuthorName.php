<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

/**
 * Who wrote a review or asked a question, in its public form (feedback.md §1.1): the first name and
 * the first letter of the last — "Sara K." — and nothing else, for shoppers and staff alike; an
 * anonymized account, or one Access no longer knows, reads as "Deleted customer" (`deleted`, names
 * empty).
 */
final readonly class AuthorName
{
    public function __construct(
        public string $firstName,
        public string $lastInitial,
        public bool $deleted,
    ) {}
}
