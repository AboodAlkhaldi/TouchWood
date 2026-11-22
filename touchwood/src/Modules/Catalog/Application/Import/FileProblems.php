<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use Modules\Catalog\Domain\Exception\ImportRefused;

/**
 * Every problem a file has, collected while it is read — never only the first — each with where it
 * is and what it must be (catalog.md §1.12).
 */
final class FileProblems
{
    /** More than this many, and the rest are the same mistake repeated. */
    public const int MAX = 500;

    /** @var list<array{at: string, problem: string}> */
    private array $problems = [];

    public function add(string $at, string $problem): void
    {
        if (count($this->problems) < self::MAX) {
            $this->problems[] = ['at' => $at, 'problem' => $problem];
        }
    }

    public function any(): bool
    {
        return $this->problems !== [];
    }

    /**
     * @throws ImportRefused
     */
    public function refuseIfAny(): void
    {
        if ($this->problems !== []) {
            throw new ImportRefused($this->problems);
        }
    }
}
