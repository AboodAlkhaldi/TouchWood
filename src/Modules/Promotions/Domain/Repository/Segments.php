<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Repository;

use DateTimeImmutable;
use Modules\Promotions\Domain\Model\Segment;
use Modules\Promotions\Domain\Model\SegmentTerms;
use Shared\Domain\ValueObject\StoreId;

/**
 * A store's customer segments and their members (promotions.md §1.5, §1.8): every read of a segment
 * names its store.
 */
interface Segments
{
    /** The store a segment belongs to; null for no such segment. */
    public function storeOf(string $segmentId): ?StoreId;

    public function byId(StoreId $store, string $segmentId): ?Segment;

    /** The segment, its row locked until the transaction ends: one change or refresh at a time. */
    public function lock(StoreId $store, string $segmentId): ?Segment;

    public function create(string $segmentId, StoreId $store, SegmentTerms $terms, DateTimeImmutable $at): void;

    /** Its name and rule's numbers; its type and audience never change (answer 23). */
    public function change(Segment $segment, SegmentTerms $terms, DateTimeImmutable $at): void;

    /** The segment gone, its members with it; coupons assigned from it keep their customers (§1.4). */
    public function delete(Segment $segment): void;

    /**
     * Every member's customer id, in id order.
     *
     * @return list<string>
     */
    public function memberIds(string $segmentId): array;

    /**
     * The members made exactly these: those gone removed, those new added now, those staying keep
     * when they were added.
     *
     * @param  list<string>  $customerIds
     * @return array{added: int, removed: int}
     */
    public function replaceMembers(Segment $segment, array $customerIds, DateTimeImmutable $at): array;

    public function markRefreshed(Segment $segment, DateTimeImmutable $at): void;

    /**
     * A page of a segment's members, keyset by customer id (handoff §5.4).
     *
     * @return list<string>
     */
    public function membersPage(string $segmentId, ?string $after, int $limit): array;

    /**
     * Every store's segments, keyset by id — the nightly refresh's one pass across stores (§1.8).
     *
     * @return list<Segment>
     */
    public function pageAcrossStores(?string $afterId, int $limit): array;
}
