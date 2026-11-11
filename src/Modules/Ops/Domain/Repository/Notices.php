<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Repository;

use DateTimeImmutable;
use Modules\Ops\Domain\Model\Notice;
use Shared\Domain\ValueObject\StoreId;

/** A store's offer notices (ops.md §1.7, §5: `ops.notices`). */
interface Notices
{
    public function add(Notice $notice): void;

    public function find(string $id): ?Notice;

    /** The notice, locked until the transaction ends: a change waits for the sender, and the sender for a change. */
    public function lock(string $id): ?Notice;

    /** Writes what a change or a cancellation may change: the text, the channels, the time, the status. */
    public function update(Notice $notice): void;

    /**
     * The store's notices, newest first, after the one named.
     *
     * @return list<Notice>
     */
    public function page(StoreId $store, ?string $afterId, int $limit): array;

    /**
     * The store's drafts, newest first, after the one named (§9.7 #1: what a campaign's page offers). One query.
     *
     * @return list<Notice>
     */
    public function drafts(StoreId $store, ?string $afterId, int $limit): array;

    /**
     * These of the store's notices, whatever their status, in no set order; another store's left out. One query.
     *
     * @param  list<string>  $ids
     * @return list<Notice>
     */
    public function named(StoreId $store, array $ids): array;

    /**
     * Starts every waiting notice whose time has come.
     *
     * @return list<string> their ids
     */
    public function startDue(DateTimeImmutable $now): array;
}
