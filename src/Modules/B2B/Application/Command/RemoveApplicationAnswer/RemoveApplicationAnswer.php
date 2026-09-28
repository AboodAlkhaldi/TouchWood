<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\RemoveApplicationAnswer;

/**
 * Takes the answer to one request out of the open draft (b2b.md §1.2, amendment 5).
 */
final readonly class RemoveApplicationAnswer
{
    public function __construct(
        public string $requestId,
    ) {}
}
