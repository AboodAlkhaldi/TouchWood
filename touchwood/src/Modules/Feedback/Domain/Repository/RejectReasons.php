<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Repository;

use DateTimeImmutable;
use Modules\Feedback\Domain\Model\RejectReason;

/**
 * The ready reasons (feedback.md §2.4 B4): one list for every store, in its order. Every change takes the
 * list's lock first (`lock`), so two admins never number it at once.
 */
interface RejectReasons
{
    /** @return list<RejectReason> in their order */
    public function all(): array;

    /** The list's lock, inside the change's transaction. */
    public function lock(): void;

    public function find(string $id): ?RejectReason;

    /** Added last. */
    public function add(string $id, string $textAr, string $textEn, DateTimeImmutable $at): int;

    public function change(string $id, string $textAr, string $textEn, DateTimeImmutable $at): void;

    /** Removed, those after it moving up. */
    public function remove(string $id): void;

    /**
     * @param  list<string>  $ids  every reason, in its new order
     */
    public function order(array $ids, DateTimeImmutable $at): void;
}
