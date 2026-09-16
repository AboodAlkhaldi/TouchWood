<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query;

use Modules\Platform\Public\Dto\MediaDto;
use Modules\Platform\Public\Dto\MediaUrlsDto;

/**
 * The read side for media. Reads never lock a row.
 */
interface MediaReader
{
    public function media(string $mediaId): ?MediaDto;

    public function urls(string $mediaId): ?MediaUrlsDto;
}
