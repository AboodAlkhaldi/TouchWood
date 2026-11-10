<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Content\Application\Command\SaveBar\SaveBar;
use Modules\Content\Application\Command\SaveBar\SaveBarHandler;
use Modules\Content\Application\Command\SavePage\SavePage;
use Modules\Content\Application\Command\SavePage\SavePageHandler;
use Modules\Content\Application\Command\SaveSmartBar\SaveSmartBar;
use Modules\Content\Application\Command\SaveSmartBar\SaveSmartBarHandler;
use Modules\Content\Application\Command\SchedulePopup\SchedulePopup;
use Modules\Content\Application\Command\SchedulePopup\SchedulePopupHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Documents\DocumentInput;
use Modules\Content\Domain\Exception\ContentError;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The last line under Content's own items (content.md §5; lessons 15, 35, 36, 95, 162): each named CHECK refuses the
| row it is for - those on nullable columns **broken with the column left NULL** - and, for the rules the use cases
| hold, the code refuses first: the constraint is dropped inside the test (its transaction rolls the drop back) and
| the use case still refuses, writing nothing.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/** @return array<string, mixed> a row the table takes, every column it needs */
function constraintRow(string $table): array
{
    $store = Fx::storeId('sa');
    $now = CarbonImmutable::now();
    $id = strtolower((string) Str::ulid());

    return match ($table) {
        'home_sections' => ['id' => $id, 'store_id' => $store, 'type' => 'HERO', 'position' => 0, 'settings' => '{}', 'created_at' => $now, 'updated_at' => $now],
        'bars' => ['store_id' => $store, 'text_ar' => 'نص', 'text_en' => 'Text', 'is_on' => true, 'updated_at' => $now],
        'footers' => ['store_id' => $store, 'columns' => '[]', 'updated_at' => $now],
        'banners' => ['id' => $id, 'store_id' => $store, 'placement' => 'HOME_PHONE', 'name' => 'Banner', 'position' => 0, 'created_at' => $now, 'updated_at' => $now],
        'popups' => ['id' => $id, 'store_id' => $store, 'name' => 'Popup', 'title_ar' => 'عنوان', 'text_ar' => 'نص', 'created_at' => $now, 'updated_at' => $now],
        'smart_bars' => ['id' => $id, 'store_id' => $store, 'scope' => 'CATEGORY', 'category_id' => constraintCategory(), 'message_ar' => 'رسالة', 'message_en' => 'Message', 'created_at' => $now, 'updated_at' => $now],
        'pages', 'projects' => ['id' => $id, 'store_id' => $store, 'created_at' => $now, 'updated_at' => $now],
        'blog_posts' => ['id' => $id, 'store_id' => $store, 'created_at' => $now, 'updated_at' => $now],
        'page_translations' => ['page_id' => constraintParent('pages'), 'locale' => 'ar', 'store_id' => $store, 'title' => 'عنوان', 'slug' => 'عنوان-'.$id, 'body' => '{"blocks": []}'],
        'blog_categories' => ['id' => $id, 'store_id' => $store, 'name_ar' => 'أدلة', 'name_en' => 'Guides', 'slug_ar' => 'أدلة', 'slug_en' => 'guides', 'position' => 0, 'created_at' => $now, 'updated_at' => $now],
        'home_section_media' => ['section_id' => constraintParent('home_sections'), 'slot' => 'LOGO', 'position' => 0, 'media_id' => Cx::image(), 'name_ar' => 'عميل'],
        'banner_images' => ['banner_id' => constraintParent('banners'), 'kind' => 'PHONE', 'locale' => 'any', 'media_id' => Cx::image()],
        'page_images' => ['page_id' => constraintParent('pages'), 'locale' => 'ar', 'ref' => 1, 'media_id' => Cx::image(), 'alt' => 'صورة'],
        'project_photos' => ['project_id' => constraintParent('projects'), 'position' => 0, 'media_id' => Cx::image()],
        default => throw new LogicException("No row for {$table}."),
    };
}

function constraintParent(string $table): string
{
    $row = constraintRow($table);
    // A store holds each home section type once: the parent section is one the store does not have yet.
    if ($table === 'home_sections') {
        DB::table('content.home_sections')->where('store_id', $row['store_id'])->where('type', 'HERO')->delete();
    }
    DB::table("content.{$table}")->insert($row);

    return (string) $row['id'];
}

function constraintCategory(): string
{
    return CatalogProducts::category();
}

/**
 * The constraint a row breaks, by its name in PostgreSQL's words.
 *
 * @param  array<string, mixed>  $row
 */
function constraintBroken(string $table, array $row): string
{
    try {
        DB::transaction(fn () => DB::table("content.{$table}")->insert($row));
    } catch (QueryException $refused) {
        preg_match('/(?:constraint|index) "([a-z_]+)"/', $refused->getMessage(), $found);

        return $found[1] ?? $refused->getMessage();
    }

    return 'accepted';
}

it('takes the base rows these cases break, so each break is the only thing wrong', function (string $table) {
    if ($table === 'home_sections') {
        DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->where('type', 'HERO')->delete();
    }

    expect(constraintBroken($table, constraintRow($table)))->toBe('accepted');
})->with(['home_sections', 'bars', 'footers', 'banners', 'popups', 'smart_bars', 'pages', 'blog_posts', 'page_translations', 'blog_categories', 'home_section_media', 'banner_images', 'page_images', 'project_photos']);

it('refuses each row its CHECK is for, a NULL left where it would slip through', function (string $table, array $with, string $constraint) {
    if ($table === 'home_sections') {
        DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->where('type', 'HERO')->delete();
    }

    // A category is made inside the test: a dataset is read before the database is there.
    $with = array_map(static fn (mixed $value): mixed => $value === '@category' ? constraintCategory() : $value, $with);

    expect(constraintBroken($table, [...constraintRow($table), ...$with]))->toBe($constraint);
})->with([
    'a section of no type' => ['home_sections', ['type' => 'BUNDLES'], 'hs_type'],
    'a section\'s settings a list' => ['home_sections', ['settings' => '[]'], 'hs_settings'],
    'a section type twice' => ['home_sections', ['type' => 'POINTS'], 'home_sections_type_unique'],
    'a bar on with no English' => ['bars', ['text_en' => null], 'bars_on_words'],
    'a bar on with no Arabic' => ['bars', ['text_ar' => null], 'bars_on_words'],
    'a bar ending at its start' => ['bars', ['starts_at' => '2027-01-01 00:00:00+00', 'ends_at' => '2027-01-01 00:00:00+00'], 'bars_dates'],
    'a footer line in one language' => ['footers', ['about_ar' => 'متجر'], 'footers_about'],
    'a footer of four columns' => ['footers', ['columns' => '[{}, {}, {}, {}]'], 'footers_columns'],
    'a banner of no placement' => ['banners', ['placement' => 'TOP'], 'banners_placement'],
    'a hero banner with a category' => ['banners', ['placement' => 'HOME_HERO', 'category_id' => '@category'], 'banners_cat_place'],
    'a banner scheduled with no English description' => ['banners', ['is_scheduled' => true, 'starts_at' => '2027-01-01 00:00:00+00', 'alt_ar' => 'وصف', 'alt_en' => null], 'banners_scheduled'],
    'a banner scheduled with no start' => ['banners', ['is_scheduled' => true, 'starts_at' => null, 'alt_ar' => 'وصف', 'alt_en' => 'Text'], 'banners_scheduled'],
    'a pop-up waiting 2 seconds' => ['popups', ['delay_seconds' => 2], 'popups_delay'],
    'a pop-up scheduled with no English title' => ['popups', ['is_scheduled' => true, 'starts_at' => '2027-01-01 00:00:00+00', 'title_en' => null, 'text_en' => 'Text'], 'popups_scheduled'],
    'a pop-up button with no link' => ['popups', ['button_ar' => 'تسوق', 'link' => null], 'popups_button'],
    'a smart bar of a category with none' => ['smart_bars', ['category_id' => null], 'sb_scope'],
    'a campaign bar with a category' => ['smart_bars', ['scope' => 'CAMPAIGN', 'run_id' => strtolower((string) Str::ulid())], 'sb_scope'],
    'a smart bar of no scope' => ['smart_bars', ['scope' => 'OTHER'], 'sb_scope'],
    'a page published with no moment' => ['pages', ['status' => 'PUBLISHED', 'published_at' => null], 'pages_published'],
    'a draft with a moment of publishing' => ['pages', ['published_at' => '2027-01-01 00:00:00+00'], 'pages_published'],
    'a post published with no cover' => ['blog_posts', ['status' => 'PUBLISHED', 'published_at' => '2027-01-01 00:00:00+00'], 'posts_cover'],
    'a text with no blocks' => ['page_translations', ['body' => '{}'], 'pt_body'],
    'an address with a slash' => ['page_translations', ['slug' => 'a/b'], 'pt_slug'],
    'an address in no language' => ['page_translations', ['locale' => 'fr'], 'pt_locale'],
    'a blog category in one language' => ['blog_categories', ['name_en' => ' '], 'bc_names'],
    'a logo of no slot' => ['home_section_media', ['slot' => 'HERO'], 'hsm_slot'],
    'a forty-first logo' => ['home_section_media', ['position' => 40], 'hsm_position'],
    'a banner image of no kind' => ['banner_images', ['kind' => 'TABLET'], 'bi_kind'],
    'a banner image of no language' => ['banner_images', ['locale' => 'fr'], 'bi_locale'],
    'a body image of ref 0' => ['page_images', ['ref' => 0], 'pi_ref'],
    'a twenty-first photo' => ['project_photos', ['position' => 20], 'pp_position'],
]);

it('refuses a file deleted under a banner, a file named by nothing, a category in use: their RESTRICT keys', function () {
    $image = constraintRow('banner_images');
    DB::table('content.banner_images')->insert($image);

    expect(fn () => DB::transaction(fn () => DB::table('platform.media')->where('id', $image['media_id'])->delete()))->toThrow(QueryException::class)
        ->and(constraintBroken('banner_images', [...constraintRow('banner_images'), 'media_id' => strtolower((string) Str::ulid())]))->toBe('bi_media');
});

it('refuses in the code first, with the constraint dropped behind it', function (string $constraint, string $table, Closure $work) {
    DB::statement("ALTER TABLE content.{$table} DROP CONSTRAINT {$constraint}");
    $before = DB::table("content.{$table}")->count();

    expect(fn () => $work())->toThrow(ContentError::class)
        ->and(DB::table("content.{$table}")->count())->toBe($before);
})->with([
    'a bar on with no English' => ['bars_on_words', 'bars', function (): void {
        Cx::editor([ContentPermissions::BAR_MANAGE]);
        app(SaveBarHandler::class)->handle(new SaveBar(Fx::storeId('sa'), 0, 'نص', null, null, true));
    }],
    'a pop-up waiting 2 seconds' => ['popups_delay', 'popups', function (): void {
        Cx::editor([ContentPermissions::POPUP_MANAGE]);
        Cx::popup(['delaySeconds' => 2]);
    }],
    'a smart bar of a category with none' => ['sb_scope', 'smart_bars', function (): void {
        Cx::editor([ContentPermissions::BAR_MANAGE]);
        app(SaveSmartBarHandler::class)->handle(new SaveSmartBar(null, Fx::storeId('sa'), 'CATEGORY', null, null, 'رسالة', 'Message', null));
    }],
    'an address with a slash' => ['pt_slug', 'page_translations', function (): void {
        Cx::editor([ContentPermissions::PAGE_MANAGE]);
        app(SavePageHandler::class)->handle(new SavePage(new DocumentInput(null, Fx::storeId('sa'), 0, 'عنوان', null, 'a/b')));
    }],
]);

it('refuses to schedule a pop-up with no English in the code first, the CHECK dropped', function () {
    Cx::editor([ContentPermissions::POPUP_MANAGE]);
    $popup = Cx::popup(['titleEn' => null]);
    DB::statement('ALTER TABLE content.popups DROP CONSTRAINT popups_scheduled');

    expect(fn () => app(SchedulePopupHandler::class)->handle(new SchedulePopup($popup, 1)))->toThrow(ContentError::class)
        ->and(DB::table('content.popups')->where('id', $popup)->value('is_scheduled'))->toBeFalse();
});
