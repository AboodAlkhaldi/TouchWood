<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeleteBlogCategory;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\Command\SaveBlogCategory\SaveBlogCategoryHandler;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Domain\Exception\CategoryNotEmpty;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Model\BlogCategory;
use Modules\Content\Domain\Repository\BlogCategories;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Content\Domain\Repository\Documents;
use Modules\Content\Domain\Repository\SlugHistory;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Deletes a blog category (content.md §1.12): **only when it holds no post**, a draft's included (`CategoryNotEmpty`),
 * under the category's lock, so a post saved into it meanwhile is seen. Its addresses are freed; the rest close up.
 */
final readonly class DeleteBlogCategoryHandler
{
    public const string PERMISSION = ContentPermissions::BLOG_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private BlogCategories $categories,
        private Documents $documents,
        private SlugHistory $history,
        private ContentLocks $locks,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(DeleteBlogCategory $command): void
    {
        $this->access->holds(self::PERMISSION);
        $store = $this->access->authorize(self::PERMISSION, $this->categories->find($command->categoryId)?->storeId, item: true);

        $this->db->transaction(function () use ($command, $store): void {
            $this->locks->lock($store->id, ContentLocks::BLOG_CATEGORIES);
            $was = $this->categories->lock($command->categoryId) ?? throw new ContentNotFound;

            if ($this->documents->postsInCategory($was->id) > 0) {
                throw new CategoryNotEmpty;
            }

            $this->categories->delete($was->id);
            $this->history->forget(SaveBlogCategoryHandler::KIND, $was->id);
            $rest = array_values(array_filter($this->categories->ofStore($store->id), static fn (BlogCategory $category): bool => $category->id !== $was->id));
            $this->categories->reorder(array_map(static fn (BlogCategory $category): string => $category->id, $rest));
            $this->platform->recordAudit(ContentAudit::entry('content.blog_category.deleted', 'content.blog_category', $was->id, $store->id, ContentAudit::diff($was->fields(), [])));
            $this->cache->invalidate();
        }, 3);
    }
}
