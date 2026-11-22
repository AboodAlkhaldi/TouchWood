<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\OrderBlogCategories;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Ordering;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Model\BlogCategory;
use Modules\Content\Domain\Repository\BlogCategories;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Shared\Application\Unauthorized;

/** Puts the store's blog categories in the order dragged (content.md §1.12): the whole order, each once; last save wins. */
final readonly class OrderBlogCategoriesHandler
{
    public const string PERMISSION = ContentPermissions::BLOG_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private BlogCategories $categories,
        private ContentLocks $locks,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(OrderBlogCategories $command): void
    {
        $store = $this->access->authorize(self::PERMISSION, $command->storeId);

        $this->db->transaction(function () use ($command, $store): void {
            $this->locks->lock($store->id, ContentLocks::BLOG_CATEGORIES);
            $present = array_map(static fn (BlogCategory $category): string => $category->id, $this->categories->ofStore($store->id));
            $ids = Ordering::of('categories', $command->categoryIds, $present);
            $this->categories->reorder($ids);
            $this->platform->recordAudit(ContentAudit::entry('content.blog_categories.ordered', 'content.blog_categories', $store->id, $store->id, AuditChanges::none()->changed('order', implode(',', $present), implode(',', $ids))));
            $this->cache->invalidate();
        }, 3);
    }
}
