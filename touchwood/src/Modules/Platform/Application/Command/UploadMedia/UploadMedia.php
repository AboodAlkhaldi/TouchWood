<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\UploadMedia;

use Modules\Platform\Public\Dto\ModuleUploadDto;
use Modules\Platform\Public\Enums\MediaVisibility;

final readonly class UploadMedia
{
    /**
     * @param  string  $path  the uploaded file on local disk (e.g. the request's temporary file)
     * @param  string  $originalFilename  the name the uploader gave it; only its base name is kept
     * @param  ModuleUploadDto|null  $forModule  set when a module uploads for its own use (stage 2b,
     *                                           P1): the permission checked is then that module's,
     *                                           not platform.media.upload. Everything else - the
     *                                           dedupe, the limits, the variants, the audit entry -
     *                                           is the same, because it is the same upload
     */
    public function __construct(
        public MediaVisibility $visibility,
        public string $path,
        public string $originalFilename,
        public ?ModuleUploadDto $forModule = null,
    ) {}
}
