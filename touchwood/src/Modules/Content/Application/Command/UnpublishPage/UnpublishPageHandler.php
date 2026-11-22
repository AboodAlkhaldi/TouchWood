<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UnpublishPage;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Documents\DocumentWriter;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Model\DocumentKind;
use Modules\Content\Domain\Repository\Documents;
use Shared\Application\Unauthorized;

/** Unpublishes a page (content.md §1.11, §4.1): a draft again; Content's own links to it are listed first by the screen (`ContentLinksTo`). */
final readonly class UnpublishPageHandler
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
    public function handle(UnpublishPage $command): void
    {
        $this->access->holds(self::PERMISSION);
        $store = $this->access->authorize(self::PERMISSION, $this->documents->find(DocumentKind::Page, $command->id)?->storeId, item: true);
        $this->writer->unpublish(DocumentKind::Page, $command->id, $command->version, $store, $this->access->staffId());
    }
}
