<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Shared\Application\PermissionScope;

/**
 * A module deleting a file **it holds for its own use** (B2B step 3, amendments 4 and 5): the file a
 * new upload replaced, the files of a discarded draft. The mirror of ModuleUploadDto: Platform checks
 * the permission that module names for the change, not `platform.media.delete`.
 *
 * Without this, a customer replacing a company document would need a media permission to remove the
 * old one, which they should never hold. Only a private file is deleted this way, and only while
 * nothing uses it any more: another module's use is never detached for the caller.
 */
final readonly class ModuleDeleteDto
{
    /**
     * @param  string  $module  the module doing the delete, e.g. "b2b"
     * @param  string  $permission  the permission that module checks for this change; it must
     *                              belong to that module, and the authorizer refuses it if no
     *                              module has declared it
     * @param  PermissionScope  $scope  where the permission is checked — global, one store, or all
     * @param  string  $mediaId  the private file to delete
     */
    public function __construct(
        public string $module,
        public string $permission,
        public PermissionScope $scope,
        public string $mediaId,
    ) {}
}
