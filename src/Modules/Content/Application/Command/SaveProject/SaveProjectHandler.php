<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveProject;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Documents\DocumentWriter;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Model\DocumentKind;
use Modules\Content\Domain\Repository\Documents;
use Shared\Application\Unauthorized;

/**
 * Saves a project (content.md §1.13) under its own permission in its store, by the rules every page, post and
 * project follows (`DocumentWriter`). An id of another store, or none, is not found.
 */
final readonly class SaveProjectHandler
{
    public const string PERMISSION = ContentPermissions::PROJECT_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Documents $documents,
        private DocumentWriter $writer,
    ) {}

    /**
     * @return string the item's id
     *
     * @throws ContentError|Unauthorized
     */
    public function handle(SaveProject $command): string
    {
        $input = $command->input;

        if ($input->id === null) {
            $store = $this->access->authorize(self::PERMISSION, $input->storeId);
        } else {
            $this->access->holds(self::PERMISSION);
            $store = $this->access->authorize(self::PERMISSION, $this->documents->find(DocumentKind::Project, $input->id)?->storeId, item: true);
        }

        return $this->writer->save(DocumentKind::Project, $input, $store, $this->access->staffId());
    }
}
