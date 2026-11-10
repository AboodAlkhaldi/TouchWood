<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Content\Application\Command\FillHomeSection\FillHomeSection;
use Modules\Content\Application\Command\FillHomeSection\FillHomeSectionHandler;
use Modules\Content\Application\Command\PublishPage\PublishPage;
use Modules\Content\Application\Command\PublishPage\PublishPageHandler;
use Modules\Content\Application\Command\SavePage\SavePage;
use Modules\Content\Application\Command\SavePage\SavePageHandler;
use Modules\Content\Application\Command\ScheduleBanner\ScheduleBanner;
use Modules\Content\Application\Command\ScheduleBanner\ScheduleBannerHandler;
use Modules\Content\Application\Command\ShowHomeSection\ShowHomeSection;
use Modules\Content\Application\Command\ShowHomeSection\ShowHomeSectionHandler;
use Modules\Content\Application\Command\UploadBannerImage\UploadBannerImage;
use Modules\Content\Application\Command\UploadBannerImage\UploadBannerImageHandler;
use Modules\Content\Application\Command\UploadPageImage\UploadPageImage;
use Modules\Content\Application\Command\UploadPageImage\UploadPageImageHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Documents\DocumentInput;
use Modules\Content\Domain\Exception\ImageNotReady;
use Modules\Content\Infrastructure\Media\ContentMediaUsage;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMedia;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMediaHandler;
use Modules\Platform\Application\Command\GenerateMediaVariants\GenerateMediaVariantsHandler;
use Modules\Platform\Domain\Exception\MediaInUse;
use Modules\Platform\Infrastructure\Queue\GenerateMediaVariantsJob;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Content's files (content.md §1.1 rule 10): uploaded under the item's own permission, one handler a permission;
| **a file in use by something scheduled, live, shown or published blocks its delete; one used by a draft, a hidden
| section or something ended is detached**, a body image's block with it, under the item's permission, audited.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Storage::fake('local');
});

/** Deletes the file as a Super Admin, answering who that was. */
function mediaDelete(string $mediaId): string
{
    $admin = Fx::staff(superAdmin: true);
    Fx::actAsStaff($admin);
    app(DeleteMediaHandler::class)->handle(new DeleteMedia($mediaId));

    return $admin;
}

it('uploads an item\'s image under that item\'s own permission, in its store, as a public file', function () {
    Cx::editor([ContentPermissions::BANNER_MANAGE]);
    $file = UploadedFile::fake()->image('banner.jpg', 300, 150);

    $id = app(UploadBannerImageHandler::class)->handle(new UploadBannerImage(Fx::storeId('sa'), (string) $file->getRealPath(), 'banner.jpg'));

    expect(DB::table('platform.media')->where('id', $id)->value('visibility'))->toBe('PUBLIC')
        ->and(fn () => app(UploadBannerImageHandler::class)->handle(new UploadBannerImage(Fx::storeId('ae'), (string) $file->getRealPath(), 'banner.jpg')))->toThrow(Unauthorized::class)
        ->and(fn () => app(UploadPageImageHandler::class)->handle(new UploadPageImage(Fx::storeId('sa'), (string) $file->getRealPath(), 'banner.jpg')))->toThrow(Unauthorized::class);

    // The same image again is the same file (Platform's dedup), used as any other.
    expect(app(UploadBannerImageHandler::class)->handle(new UploadBannerImage(Fx::storeId('sa'), (string) $file->getRealPath(), 'again.jpg')))->toBe($id);
});

it('§1.1 rule 10: an image refused as failed is uploaded again, made ready, and then Publish passes (P10 (a))', function () {
    Queue::fake();
    Storage::fake('public');
    Cx::editor([ContentPermissions::PAGE_MANAGE]);
    $file = UploadedFile::fake()->image('cabinet.jpg', 300, 150);
    $upload = static fn (): string => app(UploadPageImageHandler::class)->handle(new UploadPageImage(Fx::storeId('sa'), (string) $file->getRealPath(), 'cabinet.jpg'));
    $image = $upload();
    // The queue's job runs as the system, as a worker does.
    Fx::asSystem(static fn () => (new GenerateMediaVariantsJob($image))->failed(new RuntimeException('encoder crashed')));
    $page = app(SavePageHandler::class)->handle(new SavePage(new DocumentInput(...['id' => null, 'storeId' => Fx::storeId('sa'), 'version' => 0, 'titleAr' => 'دليل', 'titleEn' => 'Guide', 'bodyAr' => "قبل\n\n![الخزانة](image:1)", 'bodyEn' => 'Text', 'imagesAr' => [1 => $image]])));

    try {
        app(PublishPageHandler::class)->handle(new PublishPage($page, 1));

        throw new LogicException('Not refused.');
    } catch (ImageNotReady $refused) {
        // "Upload this image again."
        expect($refused->failed)->toBeTrue();
    }

    expect($upload())->toBe($image)
        ->and(DB::table('platform.media')->where('id', $image)->value('variants_status'))->toBe('PENDING');

    // The retry is the module's upload, under the page permission, and the log says so.
    $retried = json_decode((string) DB::table('platform.audit_entries')->where('action', 'platform.media.variants_retried')->where('subject_id', $image)->value('changes'), true);
    expect($retried['for_module'])->toBe([null, 'content'])
        ->and($retried['under_permission'])->toBe([null, ContentPermissions::PAGE_MANAGE])
        ->and($retried['by_upload'])->toBe([null, true]);

    Fx::asSystem(static fn () => (new GenerateMediaVariantsJob($image))->handle(app(GenerateMediaVariantsHandler::class)));
    app(PublishPageHandler::class)->handle(new PublishPage($page, 1));

    expect(DB::table('content.pages')->where('id', $page)->value('status'))->toBe('PUBLISHED');
});

it('blocks the delete of a file a live banner shows, and detaches it from a draft banner', function () {
    Cx::editor([ContentPermissions::BANNER_MANAGE]);
    $shared = Cx::image();
    $live = Cx::banner(['images' => ['PHONE:any' => $shared]]);
    app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($live, 1));
    $draft = Cx::banner(['images' => ['PHONE:any' => $shared]]);

    expect(array_map(static fn ($use): array => [$use->subjectId, $use->blocksDelete], app(ContentMediaUsage::class)->usesOf($shared)))->toEqualCanonicalizing([[$live, true], [$draft, false]])
        ->and(fn () => mediaDelete($shared))->toThrow(MediaInUse::class);

    $only = Cx::image();
    Cx::editor([ContentPermissions::BANNER_MANAGE]);
    $other = Cx::banner(['images' => ['PHONE:any' => $only]]);
    $admin = mediaDelete($only);

    expect(DB::table('content.banner_images')->where('banner_id', $other)->exists())->toBeFalse()
        ->and(DB::table('content.banners')->where('id', $other)->value('version'))->toBe(2)
        // The banner was last changed by whoever deleted its file.
        ->and(DB::table('content.banners')->where('id', $other)->value('updated_by'))->toBe($admin)
        ->and(DB::table('platform.media')->where('id', $only)->exists())->toBeFalse()
        ->and(Fx::audits('content.banner.image_detached', $other))->toBe(1);
});

it('takes a body image\'s block out of a draft page with the file, and blocks it while the page is published', function () {
    Cx::editor([ContentPermissions::PAGE_MANAGE]);
    $image = Cx::image();
    $input = static fn (array $with = []): DocumentInput => new DocumentInput(...['id' => null, 'storeId' => Fx::storeId('sa'), 'version' => 0, 'titleAr' => 'دليل', 'titleEn' => 'Guide', 'bodyAr' => "قبل\n\n![الخزانة](image:1)\n\nبعد", 'bodyEn' => 'Text', 'imagesAr' => [1 => $image], ...$with]);
    $page = app(SavePageHandler::class)->handle(new SavePage($input()));
    $published = app(SavePageHandler::class)->handle(new SavePage($input(['titleAr' => 'منشور', 'titleEn' => 'Published'])));
    app(PublishPageHandler::class)->handle(new PublishPage($published, 1));

    expect(fn () => mediaDelete($image))->toThrow(MediaInUse::class);

    Cx::editor([ContentPermissions::PAGE_MANAGE]);
    DB::table('content.pages')->where('id', $published)->update(['status' => 'DRAFT', 'published_at' => null]);
    mediaDelete($image);

    $body = json_decode((string) DB::table('content.page_translations')->where('page_id', $page)->where('locale', 'ar')->value('body'), true);

    expect(array_column($body['blocks'], 'type'))->toBe(['paragraph', 'paragraph'])
        ->and(DB::table('content.page_images')->where('page_id', $page)->exists())->toBeFalse()
        ->and(Fx::audits('content.page.image_detached', $page))->toBe(1);
});

it('blocks the delete of a logo a shown section draws, and detaches it from a hidden one', function () {
    Cx::editor([ContentPermissions::HOMEPAGE_MANAGE]);
    $section = DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->where('type', 'CLIENT_LOGOS')->first() ?? throw new LogicException('No logos section.');
    $logos = array_map(static fn (int $n): array => ['mediaId' => Cx::image(), 'nameAr' => "عميل {$n}", 'nameEn' => "Client {$n}", 'link' => null], range(1, 6));
    app(FillHomeSectionHandler::class)->handle(new FillHomeSection($section->id, 1, ['heading' => ['ar' => 'عملاؤنا', 'en' => 'Clients']], $logos));
    app(ShowHomeSectionHandler::class)->handle(new ShowHomeSection($section->id, true));

    expect(fn () => mediaDelete($logos[0]['mediaId']))->toThrow(MediaInUse::class);

    Cx::editor([ContentPermissions::HOMEPAGE_MANAGE]);
    app(ShowHomeSectionHandler::class)->handle(new ShowHomeSection($section->id, false));
    mediaDelete($logos[0]['mediaId']);

    expect(DB::table('content.home_section_media')->where('section_id', $section->id)->count())->toBe(5);
});

it('detaches under the item\'s own permission: whoever lacks it cancels the whole delete', function () {
    Cx::editor([ContentPermissions::BANNER_MANAGE]);
    $image = Cx::image();
    $banner = Cx::banner(['images' => ['PHONE:any' => $image]]);
    Fx::actAsStaff(Fx::staffWith(['platform.media.delete'], ['*']));

    expect(fn () => app(DeleteMediaHandler::class)->handle(new DeleteMedia($image)))->toThrow(Unauthorized::class)
        ->and(DB::table('content.banner_images')->where('banner_id', $banner)->exists())->toBeTrue();
});
