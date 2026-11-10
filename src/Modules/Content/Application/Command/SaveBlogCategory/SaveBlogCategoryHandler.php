<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveBlogCategory;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\EditCheck;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Model\BlogCategory;
use Modules\Content\Domain\Repository\BlogCategories;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Content\Domain\ValueObject\Slug;
use Modules\Content\Domain\ValueObject\Words;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Writes a blog category (content.md §1.12). **Both languages on save**, there being no Publish step (§1.1 rule 2);
 * names of 80 characters (the build's pick). Its addresses per language, made from the name until typed, refused when
 * another category held them now or before (`SlugTaken`); every address it had is kept (§1.1 rule 15). A new one goes
 * last. A short row: last save wins (§1.1 rule 12).
 */
final readonly class SaveBlogCategoryHandler
{
    public const string PERMISSION = ContentPermissions::BLOG_MANAGE;

    public const string KIND = 'BLOG_CATEGORY';

    public function __construct(
        private ContentAccess $access,
        private BlogCategories $categories,
        private ContentLocks $locks,
        private EditCheck $check,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @return string the category's id
     *
     * @throws ContentError|Unauthorized
     */
    public function handle(SaveBlogCategory $command): string
    {
        if ($command->categoryId === null) {
            $store = $this->access->authorize(self::PERMISSION, $command->storeId);
        } else {
            $this->access->holds(self::PERMISSION);
            $store = $this->access->authorize(self::PERMISSION, $this->categories->find($command->categoryId)?->storeId, item: true);
        }

        $name = Words::both('name', $command->nameAr, $command->nameEn, 80);
        $slugAr = Slug::typedOrFrom('slug_ar', 'ar', $command->slugAr, $name['ar'])->value;
        $slugEn = Slug::typedOrFrom('slug_en', 'en', $command->slugEn, $name['en'])->value;
        $staffId = $this->access->staffId();

        return $this->db->transaction(function () use ($command, $store, $name, $slugAr, $slugEn, $staffId): string {
            // The store's locks first, in ContentLocks' order, then the category's row.
            $this->locks->lock($store->id, ContentLocks::BLOG_CATEGORIES);
            $this->check->lockAddresses($store->id);
            $was = $command->categoryId === null ? null : ($this->categories->lock($command->categoryId) ?? throw new ContentNotFound);
            $id = $was->id ?? strtolower((string) Str::ulid());
            $category = new BlogCategory($id, $store->id, $name['ar'], $name['en'], $slugAr, $slugEn, $was->position ?? count($this->categories->ofStore($store->id)));
            $this->check->addresses($store->id, self::KIND, $id, ['ar' => $slugAr, 'en' => $slugEn]);

            if ($was === null) {
                $this->categories->add($category, $staffId);
                $this->platform->recordAudit(ContentAudit::entry('content.blog_category.added', 'content.blog_category', $id, $store->id, ContentAudit::diff([], $category->fields())));
            } else {
                $this->categories->update($category, $staffId);
                $this->platform->recordAudit(ContentAudit::entry('content.blog_category.changed', 'content.blog_category', $id, $store->id, ContentAudit::diff($was->fields(), $category->fields())));
            }

            $this->cache->invalidate();

            return $id;
        }, 3);
    }
}
