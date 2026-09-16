<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Repository;

use DateTimeImmutable;
use Modules\Platform\Domain\Exception\MediaInUse;
use Modules\Platform\Domain\Model\Media;

interface MediaRepository
{
    public function nextId(): string;

    /**
     * Reads the media without locking anything — for reads and for checks made outside a change.
     * Null for an unknown id, including any string that is not a ULID.
     */
    public function byId(string $id): ?Media;

    /**
     * Reads the media and locks its row for the rest of the transaction — for changing it.
     */
    public function lockById(string $id): ?Media;

    /**
     * Only public media is deduplicated: two companies uploading the same document must never
     * share it (owner's decision, 2026-09-16).
     */
    public function publicByChecksum(string $checksum): ?Media;

    /**
     * @return list<string> ids of PENDING media last queued at or before the given time, oldest first
     */
    public function stalePendingIds(DateTimeImmutable $queuedBefore, int $limit): array;

    /**
     * @return bool false when identical public bytes were added first by someone else
     */
    public function add(Media $media): bool;

    public function update(Media $media): void;

    /**
     * @throws MediaInUse when another module still references the media
     */
    public function delete(Media $media): void;
}
