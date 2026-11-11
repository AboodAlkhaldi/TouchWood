<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\PublishPost;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Documents\DocumentWriter;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Model\DocumentKind;
use Modules\Content\Domain\Repository\Documents;
use Shared\Application\Unauthorized;

/** Publishes a blog post (content.md §1.12, §4.1): published at once, with both languages and every image ready. */
final readonly class PublishPostHandler
{
    public const string PERMISSION = ContentPermissions::BLOG_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Documents $documents,
        private DocumentWriter $writer,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(PublishPost $command): void
    {
        $this->access->holds(self::PERMISSION);
        $store = $this->access->authorize(self::PERMISSION, $this->documents->find(DocumentKind::Post, $command->id)?->storeId, item: true);
        $this->writer->publish(DocumentKind::Post, $command->id, $command->version, $store, $this->access->staffId(), $command->read);
    }
}
