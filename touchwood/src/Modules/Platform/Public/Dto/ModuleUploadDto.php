<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Modules\Platform\Public\Enums\MediaVisibility;
use Shared\Application\PermissionScope;

/**
 * A module uploading a file **for its own use** (stage 2b, P1): a staff member's picture, a
 * company's document. Platform checks the permission that module names for the change, not
 * `platform.media.upload`.
 *
 * Without this, a staff member would need a media permission to set their own picture, and a
 * customer would need one to send B2B a company document — neither of which they should ever hold.
 * The file is stored, deduplicated, audited and deletable exactly like any other media.
 */
final readonly class ModuleUploadDto
{
    /**
     * @param  string  $module  the module doing the upload, e.g. "access"
     * @param  string  $permission  the permission that module checks for this change; it must
     *                              belong to that module, and the authorizer refuses it if no
     *                              module has declared it
     * @param  PermissionScope  $scope  where the permission is checked — global, one store, or all
     * @param  string  $path  the uploaded file on local disk
     * @param  string  $originalFilename  the name the uploader gave it; only its base name is kept
     */
    public function __construct(
        public string $module,
        public string $permission,
        public PermissionScope $scope,
        public MediaVisibility $visibility,
        public string $path,
        public string $originalFilename,
    ) {}
}
