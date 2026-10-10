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

    /**
     * As media(), for several media at once, in one query (PlatformApi::mediaOf).
     *
     * @param  list<string>  $mediaIds
     * @return array<string, MediaDto> keyed by the media id, lower-cased; an unknown id is left out
     */
    public function mediaOf(array $mediaIds): array;

    public function urls(string $mediaId): ?MediaUrlsDto;

    /**
     * As urls(), for several media at once, in one query (PlatformApi::mediaUrlsOf).
     *
     * @param  list<string>  $mediaIds
     * @return array<string, MediaUrlsDto> keyed by the media id, lower-cased; an unknown id is left out
     */
    public function urlsOf(array $mediaIds): array;

    /**
     * One page of the library, newest first (frontend.md 3.5, E5).
     *
     * Keyset, over media_created_idx: created_at DESC, id DESC, with the id breaking a tie because
     * two files uploaded together share a moment (platform.md 5.4).
     *
     * @param  bool  $includePrivate  false leaves private files out in the query itself, before the
     *                                page is cut, so the page stays full and the next one starts
     *                                where this reader's own rows end (B2B step 3, amendment 6)
     * @return list<array<string, mixed>>
     */
    public function page(ListMedia $query, bool $includePrivate): array;

    /** The bytes every file in the library takes, public and private, as uploaded (the admin home, §9.8). */
    public function totalBytes(): int;
}
