<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\UploadMedia;

use Modules\Platform\Public\Enums\MediaVisibility;

final readonly class UploadMedia
{
    /**
     * @param  string  $path  the uploaded file on local disk (e.g. the request's temporary file)
     * @param  string  $originalFilename  the name the uploader gave it; only its base name is kept
     */
    public function __construct(
        public MediaVisibility $visibility,
        public string $path,
        public string $originalFilename,
    ) {}
}
