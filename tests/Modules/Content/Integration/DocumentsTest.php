<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\Command\DeleteBlogCategory\DeleteBlogCategory;
use Modules\Content\Application\Command\DeleteBlogCategory\DeleteBlogCategoryHandler;
use Modules\Content\Application\Command\DeletePage\DeletePage;
use Modules\Content\Application\Command\DeletePage\DeletePageHandler;
use Modules\Content\Application\Command\OrderBlogCategories\OrderBlogCategories;
use Modules\Content\Application\Command\OrderBlogCategories\OrderBlogCategoriesHandler;
use Modules\Content\Application\Command\PublishPage\PublishPage;
use Modules\Content\Application\Command\PublishPage\PublishPageHandler;
use Modules\Content\Application\Command\PublishPost\PublishPost;
use Modules\Content\Application\Command\PublishPost\PublishPostHandler;
use Modules\Content\Application\Command\PublishProject\PublishProject;
use Modules\Content\Application\Command\PublishProject\PublishProjectHandler;
use Modules\Content\Application\Command\SaveBlogCategory\SaveBlogCategory;
use Modules\Content\Application\Command\SaveBlogCategory\SaveBlogCategoryHandler;
use Modules\Content\Application\Command\SavePage\SavePage;
use Modules\Content\Application\Command\SavePage\SavePageHandler;
use Modules\Content\Application\Command\SavePost\SavePost;
use Modules\Content\Application\Command\SavePost\SavePostHandler;
use Modules\Content\Application\Command\SaveProject\SaveProject;
use Modules\Content\Application\Command\SaveProject\SaveProjectHandler;
use Modules\Content\Application\Command\UnpublishPage\UnpublishPage;
use Modules\Content\Application\Command\UnpublishPage\UnpublishPageHandler;
use Modules\Content\Application\Command\UnpublishProject\UnpublishProject;
use Modules\Content\Application\Command\UnpublishProject\UnpublishProjectHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Documents\DocumentInput;
use Modules\Content\Application\Query\Documents\DocumentsHandler;
use Modules\Content\Domain\Exception\CategoryNotEmpty;
use Modules\Content\Domain\Exception\ImageNotReady;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Exception\SlugTaken;
use Modules\Content\Domain\Model\DocumentKind;
use Modules\Content\Domain\Model\Publication;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| content.md §1.11 (pages), §1.12 (the blog) and §1.13 (projects): their words and addresses per language, their
| bodies with their images, publishing at once, what a post and a project add, and the blog's categories.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/** @param  array<string, mixed>  $with */
function documentInput(array $with = []): DocumentInput
{
    return new DocumentInput(...['id' => null, 'storeId' => Fx::storeId('sa'), 'version' => 0, 'titleAr' => 'دليل التركيب', 'titleEn' => 'Fitting guide', 'bodyAr' => 'نص', 'bodyEn' => 'Text', ...$with]);
}

function documentRefusal(callable $work): string
{
    try {
        $work();
    } catch (InvalidContentInput $refused) {
        return "{$refused->attribute}:{$refused->rule}";
    } catch (MissingEnglish $missing) {
        return "{$missing->attribute}:english";
    }

    throw new LogicException('Not refused.');
}

function documentVersion(string $table, string $id): int
{
    return (int) DB::table($table)->where('id', $id)->value('version');
}

describe('§1.11 pages', function () {
    it('keeps a title, an address, a body and SEO per language, Draft until published, published at once', function () {
        Cx::editor([ContentPermissions::PAGE_MANAGE]);
        $page = app(SavePageHandler::class)->handle(new SavePage(documentInput([
            'bodyAr' => "# التركيب\n\nيناسب **كل** خزانة.", 'seoTitleAr' => 'دليل', 'seoTitleEn' => 'Guide',
        ])));

        expect(documentRefusal(fn () => app(SavePageHandler::class)->handle(new SavePage(documentInput(['seoTitleAr' => str_repeat('س', 71)])))))->toBe('seo_title_ar:too_long')
            ->and(documentRefusal(fn () => app(SavePageHandler::class)->handle(new SavePage(documentInput(['seoDescriptionAr' => str_repeat('س', 161)])))))->toBe('seo_description_ar:too_long')
            ->and(DB::table('content.pages')->where('id', $page)->value('status'))->toBe('DRAFT');

        app(PublishPageHandler::class)->handle(new PublishPage($page, 1));
        $form = app(DocumentsHandler::class)->document(DocumentKind::Page, $page);

        expect($form['status'])->toBe('PUBLISHED')
            ->and($form['texts']['ar']['body'])->toBe("# التركيب\n\nيناسب **كل** خزانة.")
            ->and($form['texts']['en']['slug'])->toBe('fitting-guide')
            ->and(app(DocumentsHandler::class)->list(DocumentKind::Page, Fx::storeId('sa'), Publication::Published)['counts'])->toBe(['PUBLISHED' => 1, 'DRAFT' => 0]);

        app(UnpublishPageHandler::class)->handle(new UnpublishPage($page, 2));
        expect(DB::table('content.pages')->where('id', $page)->value('published_at'))->toBeNull();
    });

    it('gives every image a body names its file, keeps only the images it names, and publishes only when they are ready', function () {
        Cx::editor([ContentPermissions::PAGE_MANAGE]);
        $ready = Cx::image();
        $pending = Cx::image('PENDING');
        $body = "نص\n\n![الخزانة مفتوحة](image:1)";

        expect(documentRefusal(fn () => app(SavePageHandler::class)->handle(new SavePage(documentInput(['bodyAr' => $body])))))->toBe('body_ar:image_ref');

        $page = app(SavePageHandler::class)->handle(new SavePage(documentInput(['bodyAr' => $body, 'imagesAr' => [1 => $pending, 2 => $ready]])));

        expect(DB::table('content.page_images')->where('page_id', $page)->get(['ref', 'media_id', 'alt'])->map(fn ($row) => (array) $row)->all())->toBe([['ref' => 1, 'media_id' => $pending, 'alt' => 'الخزانة مفتوحة']])
            ->and(fn () => app(PublishPageHandler::class)->handle(new PublishPage($page, 1)))->toThrow(ImageNotReady::class);
    });

    it('refuses an address another page of the store holds, now or before, and a body or address without its title', function () {
        Cx::editor([ContentPermissions::PAGE_MANAGE]);
        app(SavePageHandler::class)->handle(new SavePage(documentInput()));

        expect(fn () => app(SavePageHandler::class)->handle(new SavePage(documentInput(['titleEn' => 'Another', 'slugAr' => 'دليل-التركيب']))))->toThrow(SlugTaken::class)
            ->and(documentRefusal(fn () => app(SavePageHandler::class)->handle(new SavePage(documentInput(['titleAr' => 'أخرى', 'titleEn' => null, 'bodyEn' => 'Text'])))))->toBe('title_en:required');
    });
});

describe('§1.12 the blog', function () {
    it('publishes a post with its summary, cover and its description in both languages', function () {
        Cx::editor([ContentPermissions::BLOG_MANAGE]);
        $post = app(SavePostHandler::class)->handle(new SavePost(documentInput(['summaryAr' => 'ملخص', 'summaryEn' => 'Summary'])));

        expect(documentRefusal(fn () => app(PublishPostHandler::class)->handle(new PublishPost($post, 1))))->toBe('cover_alt_ar:required');

        app(SavePostHandler::class)->handle(new SavePost(documentInput(['id' => $post, 'version' => 1, 'summaryAr' => 'ملخص', 'summaryEn' => 'Summary', 'coverAltAr' => 'خزانة', 'coverAltEn' => 'A cabinet'])));
        expect(documentRefusal(fn () => app(PublishPostHandler::class)->handle(new PublishPost($post, 2))))->toBe('cover:required');

        app(SavePostHandler::class)->handle(new SavePost(documentInput(['id' => $post, 'version' => 2, 'summaryAr' => 'ملخص', 'summaryEn' => 'Summary', 'coverAltAr' => 'خزانة', 'coverAltEn' => 'A cabinet', 'coverMediaId' => Cx::image()])));
        app(PublishPostHandler::class)->handle(new PublishPost($post, 3));

        expect(DB::table('content.blog_posts')->where('id', $post)->value('status'))->toBe('PUBLISHED');
    });

    it('links up to 8 ready products, each once, and keeps one archived since it was linked', function () {
        Cx::editor([ContentPermissions::BLOG_MANAGE]);
        $ready = Px::ready()['product'];
        $draft = Px::product();

        expect(documentRefusal(fn () => app(SavePostHandler::class)->handle(new SavePost(documentInput(['products' => [$draft]])))))->toBe('products:product')
            ->and(documentRefusal(fn () => app(SavePostHandler::class)->handle(new SavePost(documentInput(['products' => [$ready, $ready]])))))->toBe('products:duplicate')
            ->and(documentRefusal(fn () => app(SavePostHandler::class)->handle(new SavePost(documentInput(['products' => array_fill(0, 9, $ready)])))))->toBe('products:too_many');

        $post = app(SavePostHandler::class)->handle(new SavePost(documentInput(['products' => [$ready]])));
        DB::table('catalog.products')->where('id', $ready)->update(['stage' => 'ARCHIVED', 'archived_from' => 'READY']);
        app(SavePostHandler::class)->handle(new SavePost(documentInput(['id' => $post, 'version' => 1, 'products' => [$ready]])));

        expect(DB::table('content.blog_post_products')->where('post_id', $post)->pluck('product_id')->all())->toBe([$ready]);
    });

    it('keeps a post to a category of its own store', function () {
        Cx::editor([ContentPermissions::BLOG_MANAGE], ['sa', 'ae']);
        $elsewhere = app(SaveBlogCategoryHandler::class)->handle(new SaveBlogCategory(null, Fx::storeId('ae'), 'أدلة', 'Guides'));

        expect(documentRefusal(fn () => app(SavePostHandler::class)->handle(new SavePost(documentInput(['categoryId' => $elsewhere])))))->toBe('category:category');
    });

    it('keeps categories with a name and address in both languages, dragged into order, deleted only when empty', function () {
        Cx::editor([ContentPermissions::BLOG_MANAGE]);

        expect(documentRefusal(fn () => app(SaveBlogCategoryHandler::class)->handle(new SaveBlogCategory(null, Fx::storeId('sa'), 'أدلة', null))))->toBe('name:english');

        $guides = app(SaveBlogCategoryHandler::class)->handle(new SaveBlogCategory(null, Fx::storeId('sa'), 'أدلة', 'Guides'));
        $news = app(SaveBlogCategoryHandler::class)->handle(new SaveBlogCategory(null, Fx::storeId('sa'), 'أخبار', 'News'));

        expect(fn () => app(SaveBlogCategoryHandler::class)->handle(new SaveBlogCategory(null, Fx::storeId('sa'), 'أدلة', 'Other')))->toThrow(SlugTaken::class);

        app(OrderBlogCategoriesHandler::class)->handle(new OrderBlogCategories(Fx::storeId('sa'), [$news, $guides]));
        app(SavePostHandler::class)->handle(new SavePost(documentInput(['categoryId' => $guides])));

        expect(array_column(app(DocumentsHandler::class)->blogCategories(Fx::storeId('sa')), 'posts', 'slugEn'))->toBe(['news' => 0, 'guides' => 1])
            ->and(fn () => app(DeleteBlogCategoryHandler::class)->handle(new DeleteBlogCategory($guides)))->toThrow(CategoryNotEmpty::class);

        app(DeleteBlogCategoryHandler::class)->handle(new DeleteBlogCategory($news));
        expect(DB::table('content.blog_categories')->where('store_id', Fx::storeId('sa'))->pluck('position', 'id')->all())->toBe([$guides => 0]);
    });
});

describe('§1.13 projects', function () {
    it('publishes a project with at least one photo, up to 20, its city in both languages', function () {
        Cx::editor([ContentPermissions::PROJECT_MANAGE]);
        $project = app(SaveProjectHandler::class)->handle(new SaveProject(documentInput(['cityAr' => 'الرياض'])));

        expect(documentRefusal(fn () => app(SaveProjectHandler::class)->handle(new SaveProject(documentInput(['photos' => array_map(static fn (): string => Cx::image(), range(1, 21))])))))->toBe('photos:too_many')
            ->and(documentRefusal(fn () => app(PublishProjectHandler::class)->handle(new PublishProject($project, 1))))->toBe('city:english');

        app(SaveProjectHandler::class)->handle(new SaveProject(documentInput(['id' => $project, 'version' => 1, 'cityAr' => 'الرياض', 'cityEn' => 'Riyadh'])));
        expect(documentRefusal(fn () => app(PublishProjectHandler::class)->handle(new PublishProject($project, 2))))->toBe('photos:too_few');

        $photos = [Cx::image(), Cx::image()];
        app(SaveProjectHandler::class)->handle(new SaveProject(documentInput(['id' => $project, 'version' => 2, 'cityAr' => 'الرياض', 'cityEn' => 'Riyadh', 'photos' => $photos])));
        app(PublishProjectHandler::class)->handle(new PublishProject($project, 3));

        expect(DB::table('content.project_photos')->where('project_id', $project)->orderBy('position')->pluck('media_id')->all())->toBe($photos)
            ->and(DB::table('content.projects')->where('id', $project)->value('status'))->toBe('PUBLISHED');
    });

    it('publishes and unpublishes by its own row alone, its words and images left as they are', function () {
        Cx::editor([ContentPermissions::PROJECT_MANAGE]);
        $project = app(SaveProjectHandler::class)->handle(new SaveProject(documentInput(['photos' => [Cx::image()], 'bodyAr' => '![صورة](image:1)', 'imagesAr' => [1 => Cx::image()]])));
        /** @var ArrayObject<int, string> $written */
        $written = new ArrayObject;
        DB::listen(function (QueryExecuted $query) use ($written): void {
            if (preg_match('/^\s*(insert|delete|update)/i', $query->sql) === 1 && preg_match('/content\.|"content"\./', $query->sql) === 1) {
                $written[] = $query->sql;
            }
        });

        app(PublishProjectHandler::class)->handle(new PublishProject($project, 1));
        app(UnpublishProjectHandler::class)->handle(new UnpublishProject($project, 2));

        expect(array_filter($written->getArrayCopy(), static fn (string $sql): bool => preg_match('/translations|images|photos|products/', $sql) === 1))->toBe([])
            ->and($written->getArrayCopy())->not->toBe([]);
    });

    it('removes an item\'s rows with it: translations, images, photos and products', function () {
        Cx::editor([ContentPermissions::PAGE_MANAGE]);
        $page = app(SavePageHandler::class)->handle(new SavePage(documentInput(['bodyAr' => '![صورة](image:1)', 'imagesAr' => [1 => Cx::image()]])));

        app(DeletePageHandler::class)->handle(new DeletePage($page));

        expect(DB::table('content.page_translations')->where('page_id', $page)->exists())->toBeFalse()
            ->and(DB::table('content.page_images')->where('page_id', $page)->exists())->toBeFalse();
    });
});
