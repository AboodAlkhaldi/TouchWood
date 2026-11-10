<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeletePage;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Documents\DocumentWriter;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Model\DocumentKind;
use Modules\Content\Domain\Repository\Documents;
use Shared\Application\Unauthorized;

/** Deletes a draft of a page (content.md §1.1 rule 11): a published one is unpublished first; its addresses are freed. */
final readonly class DeletePageHandler
{
    public const string PERMISSION = ContentPermissions::PAGE_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Documents $documents,
        private DocumentWriter $writer,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(DeletePage $command): void
    {
        $this->access->holds(self::PERMISSION);
        $store = $this->access->authorize(self::PERMISSION, $this->documents->find(DocumentKind::Page, $command->id)?->storeId, item: true);
        $this->writer->delete(DocumentKind::Page, $command->id, $store);
    }
}
