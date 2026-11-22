<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Content\Application\Command\DeleteBlogCategory\DeleteBlogCategory;
use Modules\Content\Application\Command\DeleteBlogCategory\DeleteBlogCategoryHandler;
use Modules\Content\Application\Command\OrderBlogCategories\OrderBlogCategories;
use Modules\Content\Application\Command\OrderBlogCategories\OrderBlogCategoriesHandler;
use Modules\Content\Application\Command\SaveBlogCategory\SaveBlogCategory;
use Modules\Content\Application\Command\SaveBlogCategory\SaveBlogCategoryHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\ContentActions\ContentActionsForReader;
use Modules\Content\Application\Query\Documents\DocumentsHandler;
use Modules\Content\Domain\Model\DocumentKind;
use Modules\Content\Presentation\Http\Resource\BlogCategoriesPageData;
use Modules\Content\Presentation\Http\Resource\BlogCategoryData;
use Shared\Domain\Error\DomainError;

/*
| W8 · Blog, its Categories tab (content.md §4.4, §1.12): the store's categories in their order, dragged or moved to the
| top or the bottom; Add Category and a category's change in a dialog (name and address per language); Delete, refused
| while it holds posts ("Move its posts first."). Under `content.blog.manage` in the store, which each handler asks again.
*/
final readonly class BlogCategoriesController
{
    /** @var list<string> */
    private const array BOXES = ['name_ar', 'name_en', 'slug_ar', 'slug_en'];

    public function __construct(
        private ContentScreens $screens,
    ) {}

    public function index(Request $request, DocumentsHandler $documents, ContentActionsForReader $actions): Response
    {
        $store = $this->screens->store($request, ContentPermissions::BLOG_MANAGE);
        $counts = $documents->list(DocumentKind::Post, $store->id, null)['counts'];

        return $this->screens->render('Content/Admin/Blog/Categories', (new BlogCategoriesPageData(
            $this->screens->stores(ContentPermissions::BLOG_MANAGE),
            $store->code,
            array_map(BlogCategoryData::fromRow(...), $documents->blogCategories($store->id)),
            array_sum($counts),
            $actions->translationReady(),
        ))->toArray(), ['content::admin_documents', 'content::admin_blog', 'catalog::admin']);
    }

    public function save(Request $request, SaveBlogCategoryHandler $save, ?string $category = null): RedirectResponse
    {
        try {
            $save->handle(new SaveBlogCategory(
                $category,
                Inputs::text($request, 'store'),
                Inputs::typed($request, 'name_ar'),
                Inputs::typed($request, 'name_en'),
                Inputs::typed($request, 'slug_ar'),
                Inputs::typed($request, 'slug_en'),
            ));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, self::BOXES);
        }

        return back()->with('status', __('content::admin_blog.categories.saved'));
    }

    public function order(Request $request, OrderBlogCategoriesHandler $order): RedirectResponse
    {
        try {
            $order->handle(new OrderBlogCategories(Inputs::text($request, 'store'), Inputs::texts($request, 'ids')));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return back();
    }

    public function delete(Request $request, string $category, DeleteBlogCategoryHandler $delete): RedirectResponse
    {
        try {
            $delete->handle(new DeleteBlogCategory($category));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return back()->with('status', __('content::admin_blog.categories.deleted'));
    }
}
