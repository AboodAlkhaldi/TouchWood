<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

use DateTimeImmutable;

/**
 * Redirect sheets read and checked, waiting for Apply Sheet (content.md §1.15, §5: `content.redirect_uploads`).
 * Each line: its row in the sheet, both addresses as kept (as typed, when refused), the result - NEW, SAME, CHANGE
 * or REFUSED - the refusal's rule, and both addresses as typed, which Apply reads again.
 */
interface RedirectUploads
{
    /**
     * @param  list<array{line: int, old: string, new: string, result: string, reason: string|null, typed_old: string, typed_new: string}>  $lines
     */
    public function add(string $id, array $lines, ?string $by, DateTimeImmutable $at): void;

    /**
     * A sheet made since `$since`, locked when asked.
     *
     * @return array{lines: list<array{line: int, old: string, new: string, result: string, reason: string|null, typed_old: string, typed_new: string}>, state: string, created_by: string|null, created_at: DateTimeImmutable}|null
     */
    public function find(string $id, DateTimeImmutable $since, bool $lock = false): ?array;

    public function applied(string $id, DateTimeImmutable $at): void;

    /** Removes every sheet made before `$before` (dropped after 24 hours, §9.3 #31). */
    public function pruneBefore(DateTimeImmutable $before): void;
}
