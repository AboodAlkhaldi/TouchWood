<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Staff;

/** What staff read of a store's returns and an order's private files (sales.md §3), in SQL (`DatabaseStaffReturnReads`). */
interface StaffReturnReads
{
    /**
     * Returns of these stores (null: every store), newest first, after the return with that id.
     *
     * @param  list<string>|null  $storeIds
     * @return list<array<string, mixed>>
     */
    public function returns(?array $storeIds, ?string $status, ?string $afterReturnId, int $limit): array;

    /** @return array<string, mixed>|null the return with its order's number, customer and currency */
    public function returnOf(string $returnId): ?array;

    /** @return list<array<string, mixed>> the return's lines with the order lines' snapshots */
    public function lines(string $returnId): array;

    /** @return list<array<string, mixed>> the return's photos, in their order */
    public function photos(string $returnId): array;

    /** The store of the order or return a private file belongs to — a transfer document or a return photo; null for any other file. */
    public function storeOfFile(string $mediaId): ?string;
}
