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
     * Starts every waiting notice whose time has come.
     *
     * @return list<string> their ids
     */
    public function startDue(DateTimeImmutable $now): array;
}
