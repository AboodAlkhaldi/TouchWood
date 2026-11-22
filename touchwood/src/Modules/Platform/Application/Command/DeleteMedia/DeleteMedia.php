<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\DeleteMedia;

use Modules\Platform\Public\Dto\ModuleDeleteDto;

final readonly class DeleteMedia
{
    /**
     * @param  ModuleDeleteDto|null  $forModule  set when a module deletes a file it holds for its own
     *                                           use (B2B step 3, amendments 4 and 5): the permission
     *                                           checked is then that module's, not
     *                                           platform.media.delete; only a private file is
     *                                           deleted, and any remaining use refuses it rather
     *                                           than being detached
     */
    public function __construct(
        public string $mediaId,
        public ?ModuleDeleteDto $forModule = null,
    ) {}
}
