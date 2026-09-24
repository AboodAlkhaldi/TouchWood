<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query;

use Modules\Platform\Application\Query\ListMedia\ListMedia;
use Modules\Platform\Public\Dto\MediaDto;
use Modules\Platform\Public\Dto\MediaUrlsDto;

/**
 * The read side for media. Reads never lock a row.
 */
interface MediaReader
{
    public function media(string $mediaId): ?MediaDto;

    public function urls(string $mediaId): ?MediaUrlsDto;

    /**
     * One page of the library, newest first (frontend.md 3.5, E5).
     *
     * Keyset, over media_created_idx: created_at DESC, id DESC, with the id breaking a tie because
     * two files uploaded together share a moment (platform.md 5.4).
     *
     * @return list<array<string, mixed>>
     */
    public function page(ListMedia $query): array;
}
