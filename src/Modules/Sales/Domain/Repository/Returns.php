<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Repository;

use DateTimeImmutable;
use Modules\Sales\Domain\Model\ReturnState;

/**
 * A delivered order's returns (sales.md §1.8, §5). Every change locks the order first, then the return.
 */
interface Returns
{
    /** @return array{string, string}|null the return's order and store */
    public function orderOf(string $returnId): ?array;

    public function lock(string $returnId): ?ReturnState;

    /** The order's return deadline, fixed when it was delivered; null before. */
    public function deadline(string $orderId): ?DateTimeImmutable;

    /**
     * The order's lines that may be returned — every bought part and the gift — with how many pieces each
     * holds and how many other returns count (§1.8: asked in one requested or accepted; received in one
     * received or completed).
     *
     * @return array<string, array{quantity: int, counted: int}> by order line id
     */
    public function returnable(string $orderId): array;

    /**
     * @param  array<string, int>  $lines  order line id => pieces asked
     */
    public function request(string $returnId, string $orderId, string $storeId, string $reason, ?string $reasonOther, array $lines, DateTimeImmutable $at): void;

    public function withdraw(string $returnId, DateTimeImmutable $at): void;

    /** Accepted, or refused with staff's words the customer sees. */
    public function decide(string $returnId, string $status, ?string $staffReason, string $by, DateTimeImmutable $at): void;

    public function close(string $returnId, DateTimeImmutable $at): void;

    /** @return int how many photos the return holds */
    public function photos(string $returnId): int;

    public function addPhoto(string $returnId, string $mediaId, int $position): void;
}
