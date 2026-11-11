<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Content\Application\Command\DeletePage\DeletePage;
use Modules\Content\Application\Command\DeletePage\DeletePageHandler;
use Modules\Content\Application\Command\DeletePost\DeletePost;
use Modules\Content\Application\Command\DeletePost\DeletePostHandler;
use Modules\Content\Application\Command\DeleteProject\DeleteProject;
use Modules\Content\Application\Command\DeleteProject\DeleteProjectHandler;
use Modules\Content\Application\Command\PublishPage\PublishPage;
use Modules\Content\Application\Command\PublishPage\PublishPageHandler;
use Modules\Content\Application\Command\PublishPost\PublishPost;
use Modules\Content\Application\Command\PublishPost\PublishPostHandler;
use Modules\Content\Application\Command\PublishProject\PublishProject;
use Modules\Content\Application\Command\PublishProject\PublishProjectHandler;
use Modules\Content\Application\Command\SavePage\SavePage;
use Modules\Content\Application\Command\SavePage\SavePageHandler;
use Modules\Content\Application\Command\SavePost\SavePost;
use Modules\Content\Application\Command\SavePost\SavePostHandler;
use Modules\Content\Application\Command\SaveProject\SaveProject;
use Modules\Content\Application\Command\SaveProject\SaveProjectHandler;
use Modules\Content\Application\Command\UnpublishPage\UnpublishPage;
use Modules\Content\Application\Command\UnpublishPage\UnpublishPageHandler;
use Modules\Content\Application\Command\UnpublishPost\UnpublishPost;
use Modules\Content\Application\Command\UnpublishPost\UnpublishPostHandler;
use Modules\Content\Application\Command\UnpublishProject\UnpublishProject;
use Modules\Content\Application\Command\UnpublishProject\UnpublishProjectHandler;
use Modules\Content\Application\Documents\DocumentInput;
use Modules\Content\Application\Query\ContentActions\ContentActionsForReader;
use Modules\Content\Application\Query\Documents\DocumentsHandler;
use Modules\Content\Application\Query\ScreenReads;
use Modules\Content\Domain\Model\DocumentKind;
use Modules\Content\Domain\Model\Publication;
use Modules\Content\Presentation\Http\Resource\BlogCategoryData;
use Modules\Content\Presentation\Http\Resource\ContentStoreData;
use Modules\Content\Presentation\Http\Resource\DocumentData;
use Modules\Content\Presentation\Http\Resource\DocumentFormPageData;
use Modules\Content\Presentation\Http\Resource\DocumentRowData;
use Modules\Content\Presentation\Http\Resource\DocumentsPageData;
use Modules\Content\Presentation\Http\Resource\ProductChoiceData;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/*
| W7 · Pages, W8 · Blog's posts, W9 · Projects (content.md §4.4, §1.11-§1.13): one controller for the three kinds, as
| one writer serves them (`DocumentWriter`); each route names its kind, and each change goes through that kind's own
| handler under its own permission (`content.page.manage`, `content.blog.manage`, `content.project.manage`). A list,
| an item's own page (New and each one's), Save Draft / Publish / Save Changes, Unpublish, Delete, and the products a
| post or project may link. A plain visit gets the page, Show More and the product search the JSON.
*/
final readonly class DocumentsController
{
    /** @var list<string> the form's boxes, by the names it posts */
    private const array BOXES = [
        'title_ar', 'title_en', 'slug_ar', 'slug_en', 'body_ar', 'body_en', 'seo_title_ar', 'seo_title_en', 'seo_description_ar', 'seo_description_en',
        'share_image_ar', 'share_image_en', 'summary_ar', 'summary_en', 'cover', 'cover_alt_ar', 'cover_alt_en', 'category', 'city_ar', 'city_en', 'products', 'photos',
    ];

    public function __construct(
        private ContentScreens $screens,
    ) {}

    public function index(Request $request, string $kind, DocumentsHandler $documents): Response|JsonResponse
    {
        $type = self::kind($kind);
        $store = $this->screens->store($request, DocumentsHandler::permission($type));
        // Pages and projects by tab, Published first; the blog's posts all at once, their state a column (W8).
        $tab = $type === DocumentKind::Post ? null : (Inputs::text($request, 'tab') === 'draft' ? Publication::Draft : Publication::Published);
        $category = $type === DocumentKind::Post ? Inputs::text($request, 'category') : null;
        $read = $documents->list($type, $store->id, $tab, Inputs::text($request, 'after'), $category);
        $rows = array_map(DocumentRowData::fromRow(...), $read['rows']);
        $next = count($rows) === ScreenReads::PAGE ? $rows[count($rows) - 1]->id : null;

        if ($request->expectsJson()) {
            return new JsonResponse(['rows' => array_map(static fn (DocumentRowData $row): array => $row->toArray(), $rows), 'next' => $next]);
        }

        $categories = $type === DocumentKind::Post ? array_map(BlogCategoryData::fromRow(...), $documents->blogCategories($store->id)) : [];

        return $this->screens->render(self::component($type, 'Index'), (new DocumentsPageData(
            $this->screens->stores(DocumentsHandler::permission($type)),
            $store->code,
            $store->timezone,
            $kind,
            $tab === Publication::Draft ? 'draft' : 'published',
            $read['counts'],
            $rows,
            $next,
            $categories,
            $category,
            array_sum($read['counts']),
        ))->toArray(), self::words($type));
    }

    public function create(Request $request, string $kind, DocumentsHandler $documents, ContentActionsForReader $actions): Response
    {
        $type = self::kind($kind);
        $store = $this->screens->store($request, DocumentsHandler::permission($type));

        return $this->form($type, $store, null, $documents, $actions);
    }

    // A route's own parameter comes first, the kind its route names after it (`defaults`): Laravel passes them in that order.
    public function edit(string $document, string $kind, DocumentsHandler $documents, PlatformApi $platform, ContentActionsForReader $actions): Response
    {
        $type = self::kind($kind);
        $raw = $documents->document($type, $document);
        $store = $platform->store(StoreId::fromString((string) ($raw['storeId'] ?? '')));

        /** @var StoreDto $store the handler found the item in it */
        return $this->form($type, $store, DocumentData::fromRead($raw), $documents, $actions);
    }

    public function store(Request $request, string $kind, DocumentsHandler $documents): RedirectResponse
    {
        return $this->save($request, self::kind($kind), null, $documents);
    }

    public function update(Request $request, string $document, string $kind, DocumentsHandler $documents): RedirectResponse
    {
        return $this->save($request, self::kind($kind), $document, $documents);
    }

    public function unpublish(Request $request, string $document, string $kind): RedirectResponse
    {
        $type = self::kind($kind);
        $version = Inputs::version($request);

        return self::act($request, fn () => match ($type) {
            DocumentKind::Page => app(UnpublishPageHandler::class)->handle(new UnpublishPage($document, $version)),
            DocumentKind::Post => app(UnpublishPostHandler::class)->handle(new UnpublishPost($document, $version)),
            DocumentKind::Project => app(UnpublishProjectHandler::class)->handle(new UnpublishProject($document, $version)),
        }, "content::admin_documents.{$type->key()}.unpublished");
    }

    public function delete(Request $request, string $document, string $kind, DocumentsHandler $documents): RedirectResponse
    {
        $type = self::kind($kind);
        $code = $this->code($type, (string) ($documents->document($type, $document)['storeId'] ?? ''));

        try {
            match ($type) {
                DocumentKind::Page => app(DeletePageHandler::class)->handle(new DeletePage($document)),
                DocumentKind::Post => app(DeletePostHandler::class)->handle(new DeletePost($document)),
                DocumentKind::Project => app(DeleteProjectHandler::class)->handle(new DeleteProject($document)),
            };
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return redirect(self::list($type).'?store='.$code)->with('status', __("content::admin_documents.{$type->key()}.deleted"));
    }

    /** The ready products a post or project may link, found by a name or a code (§1.12, §1.13). */
    public function products(Request $request, string $kind, DocumentsHandler $documents): JsonResponse
    {
        $found = $documents->findProducts(self::kind($kind), Inputs::text($request, 'store'), Inputs::text($request, 'q'), Inputs::text($request, 'after'));

        return new JsonResponse(['products' => array_map(static fn (array $product): array => ProductChoiceData::fromRead($product)->toArray(), $found['products']), 'next' => $found['next']]);
    }

    /**
     * Save Draft, or Publish: the item saved, then published at the version the save made - both with the tick when it
     * was given (§1.3, P14). A new one whose Publish is refused stays a draft, and its own page says why. A published
     * one's Save Changes is a save alone.
     */
    private function save(Request $request, DocumentKind $type, ?string $id, DocumentsHandler $documents): RedirectResponse
    {
        $input = self::input($request, $id);

        try {
            $saved = match ($type) {
                DocumentKind::Page => app(SavePageHandler::class)->handle(new SavePage($input)),
                DocumentKind::Post => app(SavePostHandler::class)->handle(new SavePost($input)),
                DocumentKind::Project => app(SaveProjectHandler::class)->handle(new SaveProject($input)),
            };
        } catch (DomainError $error) {
            return Refusals::back($request, $error, self::BOXES);
        }

        $raw = $documents->document($type, $saved);
        $read = DocumentData::fromRead($raw);
        $code = $this->code($type, (string) ($raw['storeId'] ?? ''));
        $back = self::list($type).'?store='.$code;

        if (Inputs::text($request, 'intent') === 'publish' && $read->status === Publication::Draft->value) {
            try {
                match ($type) {
                    DocumentKind::Page => app(PublishPageHandler::class)->handle(new PublishPage($saved, $read->version, $input->read)),
                    DocumentKind::Post => app(PublishPostHandler::class)->handle(new PublishPost($saved, $read->version, $input->read)),
                    DocumentKind::Project => app(PublishProjectHandler::class)->handle(new PublishProject($saved, $read->version, $input->read)),
                };
            } catch (DomainError $error) {
                return Refusals::to(self::forms($type)."/{$saved}", $error, self::BOXES);
            }

            return redirect($back)->with('status', __("content::admin_documents.{$type->key()}.published"));
        }

        return redirect($back)->with('status', __("content::admin_documents.{$type->key()}.saved"));
    }

    private function form(DocumentKind $type, StoreDto $store, ?DocumentData $document, DocumentsHandler $documents, ContentActionsForReader $actions): Response
    {
        $images = $document === null ? [] : $this->screens->images($document->mediaIds());
        $products = $document === null || $document->products === [] ? [] : array_map(ProductChoiceData::fromRead(...), $documents->linkedProducts($document->products));
        $categories = $type === DocumentKind::Post ? array_map(BlogCategoryData::fromRow(...), $documents->blogCategories($store->id)) : [];
        $linkedFrom = [];

        foreach ($document === null ? [] : $documents->linksTo($type, $document->id) as $link) {
            $linkedFrom[$link['place']] = ($linkedFrom[$link['place']] ?? 0) + 1;
        }

        return $this->screens->render(self::component($type, 'Form'), (new DocumentFormPageData(
            ContentStoreData::of($store),
            $type->key(),
            $document,
            $images,
            $products,
            $categories,
            url('/'.$store->code),
            $type->pathPrefix(),
            $actions->translationReady(),
            $linkedFrom,
        ))->toArray(), self::words($type));
    }

    /** What the form sent, as the writer takes it: a value of the wrong type is no value, for the writer to refuse. */
    private static function input(Request $request, ?string $id): DocumentInput
    {
        $refs = static function (string $key) use ($request): array {
            $images = [];

            foreach (Inputs::map($request, $key) as $ref => $media) {
                if ($media !== null && preg_match('/\A[0-9]{1,6}\z/', (string) $ref) === 1) {
                    $images[(int) $ref] = $media;
                }
            }

            return $images;
        };

        return new DocumentInput(
            $id,
            Inputs::text($request, 'store'),
            Inputs::version($request),
            Inputs::typed($request, 'title_ar'),
            Inputs::typed($request, 'title_en'),
            Inputs::typed($request, 'slug_ar'),
            Inputs::typed($request, 'slug_en'),
            Inputs::typed($request, 'body_ar'),
            Inputs::typed($request, 'body_en'),
            $refs('images_ar'),
            $refs('images_en'),
            Inputs::typed($request, 'seo_title_ar'),
            Inputs::typed($request, 'seo_title_en'),
            Inputs::typed($request, 'seo_description_ar'),
            Inputs::typed($request, 'seo_description_en'),
            Inputs::text($request, 'share_ar'),
            Inputs::text($request, 'share_en'),
            Inputs::typed($request, 'summary_ar'),
            Inputs::typed($request, 'summary_en'),
            Inputs::text($request, 'cover_media_id'),
            Inputs::typed($request, 'cover_alt_ar'),
            Inputs::typed($request, 'cover_alt_en'),
            Inputs::text($request, 'category_id'),
            Inputs::typed($request, 'city_ar'),
            Inputs::typed($request, 'city_en'),
            Inputs::texts($request, 'products'),
            Inputs::texts($request, 'photos'),
            Inputs::flag($request, 'translated'),
            Inputs::flag($request, 'read'),
        );
    }

    private function code(DocumentKind $type, string $storeId): string
    {
        foreach ($this->screens->stores(DocumentsHandler::permission($type)) as $store) {
            if ($store->id === strtolower($storeId)) {
                return $store->code;
            }
        }

        return '';
    }

    /** The route's kind: `page`, `post` or `project`; the routes name no other. */
    private static function kind(string $kind): DocumentKind
    {
        return DocumentKind::from(strtoupper($kind));
    }

    private static function list(DocumentKind $type): string
    {
        return match ($type) {
            DocumentKind::Page => '/admin/content/pages',
            DocumentKind::Post => '/admin/content/blog',
            DocumentKind::Project => '/admin/content/projects',
        };
    }

    /** Where an item's own page is, under its id. */
    private static function forms(DocumentKind $type): string
    {
        return $type === DocumentKind::Post ? '/admin/content/blog/posts' : self::list($type);
    }

    private static function component(DocumentKind $type, string $page): string
    {
        return match ($type) {
            DocumentKind::Page => "Content/Admin/Pages/{$page}",
            DocumentKind::Post => "Content/Admin/Blog/{$page}",
            DocumentKind::Project => "Content/Admin/Projects/{$page}",
        };
    }

    /**
     * @return list<string>
     */
    private static function words(DocumentKind $type): array
    {
        // Catalog's words name the dragged lists' handles and Move to Top and Bottom (SortableList).
        return ['content::admin_documents', 'catalog::admin', match ($type) {
            DocumentKind::Page => 'content::admin_pages',
            DocumentKind::Post => 'content::admin_blog',
            DocumentKind::Project => 'content::admin_projects',
        }];
    }

    /**
     * @param  callable(): void  $change
     */
    private static function act(Request $request, callable $change, string $toast): RedirectResponse
    {
        try {
            $change();
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return back()->with('status', __($toast));
    }
}
