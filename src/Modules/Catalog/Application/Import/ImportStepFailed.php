<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use RuntimeException;
use Shared\Domain\Error\DomainError;
use Throwable;

/**
 * Bringing an import's products in stopped at one product, or at one name being made (catalog.md
 * §1.12, page part 3): everything rolls back, and the page says where and why.
 */
final class ImportStepFailed extends RuntimeException
{
    public function __construct(
        public readonly string $where,
        Throwable $previous,
    ) {
        parent::__construct("{$where}: {$previous->getMessage()}", 0, $previous);
    }

    /**
     * Why, for the import's page: the refusal's own words and its key, or — for anything the domain
     * did not refuse — that the failed jobs screen holds the rest.
     */
    public function reason(): string
    {
        $previous = $this->getPrevious();

        return $previous instanceof DomainError
            ? "{$this->where}: {$previous->getMessage()} ({$previous->type()})"
            : "{$this->where}: an unexpected error; the failed jobs screen has its details";
    }
}
