<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListMedia;

/**
 * The media library's read (frontend.md 3.5, E5).
 *
 * Paged by keyset, newest first, over media_created_idx - the index the table was built with
 * (platform.md 5.4). The cursor is the last file seen: its moment and its id, because two files
 * uploaded together share a moment.
 */
final readonly class ListMedia
{
    public function __construct(
        public ?string $cursorCreatedAt = null,
        public ?string $cursorId = null,
        public int $perPage = 24,
    ) {}
}
